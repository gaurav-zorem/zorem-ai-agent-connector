<?php
/**
 * Whether this server passes the Authorization header on to WordPress.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Auth\BearerTokenResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Asks the site, over HTTP, whether a bearer token sent to it reaches PHP.
 *
 * Every way an app signs in to an MCP address ends in an `Authorization:
 * Bearer` header. Many CGI and FastCGI hosts drop that header before PHP runs
 * unless the server is told to pass it on (`CGIPassAuth On`, `SetEnvIf
 * Authorization`, or `fastcgi_param HTTP_AUTHORIZATION`). On such a host the
 * whole connect flow succeeds — the sign-in screen, the approval, the token —
 * and then the first MCP call arrives with no credential and is refused.
 * Claude reports that only as "returned an error when connecting", and
 * nothing in WordPress said why.
 *
 * Inspecting `$_SERVER` from an admin page cannot answer it: the admin's own
 * request carries no Authorization header. So the site sends itself one: a
 * one-time random probe, posted to a route that says only whether that exact
 * value arrived. It never echoes a header back. A loopback that fails is
 * reported as "could not check", never as a pass or a fail (PATTERNS 6).
 */
final class AuthorizationHeaderCheck {

	/**
	 * REST route of the probe, below the plugin's namespace. Not below `mcp/`,
	 * so no agent credential is ever resolved on it.
	 */
	public const ROUTE = 'header-check';

	/** Results. */
	public const STATUS_PASSED   = 'passed';
	public const STATUS_STRIPPED = 'stripped';
	public const STATUS_UNKNOWN  = 'unknown';

	/**
	 * Transient holding the probe value while a check runs.
	 */
	private const PROBE_TRANSIENT = 'ai_agent_os_header_probe';

	/**
	 * Transient holding the last result.
	 */
	private const RESULT_TRANSIENT = 'ai_agent_os_header_check';

	/**
	 * How long a pass is trusted, in seconds: half a day.
	 */
	private const PASSED_TTL = 43200;

	/**
	 * How long a failure or a failed check is kept, in seconds: five minutes,
	 * so a fixed server shows as fixed soon after, without a loopback on every
	 * page view while it is broken.
	 */
	private const FAILED_TTL = 300;

	/**
	 * Header parsing shared with agent tokens.
	 *
	 * @var BearerTokenResolver
	 */
	private BearerTokenResolver $bearer;

	/**
	 * Constructor.
	 *
	 * @param BearerTokenResolver $bearer Header parsing shared with agent tokens.
	 */
	public function __construct( BearerTokenResolver $bearer ) {
		$this->bearer = $bearer;
	}

	/**
	 * Hook the probe route and the Site Health test.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_filter( 'site_status_tests', array( $this, 'add_site_health_test' ) );
	}

	/**
	 * Register the probe route.
	 */
	public function register_route(): void {
		register_rest_route(
			Routes::NAMESPACE,
			'/' . self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'answer' ),
				// Public by design: it answers one yes/no about a value only this site knows, and reads nothing else.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Say whether the probe sent by run() arrived in this request's Authorization header.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function answer( \WP_REST_Request $request ): \WP_REST_Response {
		$probe = get_transient( self::PROBE_TRANSIENT );
		$token = $this->bearer->presented_token( $request );

		$received = is_string( $probe ) && '' !== $probe && null !== $token && hash_equals( $probe, $token );

		$response = new \WP_REST_Response( array( 'received' => $received ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The last result, checking again when there is none.
	 *
	 * @param bool $refresh Check again even when a result is kept.
	 * @return array{status: string, detail: string, checked_at: string}
	 */
	public function result( bool $refresh = false ): array {
		$kept = get_transient( self::RESULT_TRANSIENT );

		if ( ! $refresh && is_array( $kept ) && isset( $kept['status'] ) ) {
			return array(
				'status'     => (string) $kept['status'],
				'detail'     => (string) ( $kept['detail'] ?? '' ),
				'checked_at' => (string) ( $kept['checked_at'] ?? '' ),
			);
		}

		$result = $this->run();

		set_transient( self::RESULT_TRANSIENT, $result, self::STATUS_PASSED === $result['status'] ? self::PASSED_TTL : self::FAILED_TTL );

		return $result;
	}

	/**
	 * Send the site a one-time probe and read its answer.
	 *
	 * @return array{status: string, detail: string, checked_at: string}
	 */
	private function run(): array {
		$checked_at = gmdate( 'Y-m-d H:i:s' );
		$probe      = wp_generate_password( 40, false );

		set_transient( self::PROBE_TRANSIENT, $probe, MINUTE_IN_SECONDS );

		try {
			$response = wp_remote_post(
				rest_url( Routes::NAMESPACE . '/' . self::ROUTE ),
				array(
					'timeout'   => 10,
					'headers'   => array(
						'Authorization' => 'Bearer ' . $probe,
						'Accept'        => 'application/json',
					),
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as core's loopback checks apply it.
					'sslverify' => (bool) apply_filters( 'https_local_ssl_verify', false ),
				)
			);
		} finally {
			delete_transient( self::PROBE_TRANSIENT );
		}

		if ( is_wp_error( $response ) ) {
			return self::outcome( self::STATUS_UNKNOWN, $response->get_error_message(), $checked_at );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || ! array_key_exists( 'received', $body ) ) {
			/* translators: %d: HTTP status code. */
			return self::outcome( self::STATUS_UNKNOWN, sprintf( __( 'The site answered its own check with HTTP %d instead of a result.', 'zorem-ai-agent-connector' ), $code ), $checked_at );
		}

		return self::outcome( true === $body['received'] ? self::STATUS_PASSED : self::STATUS_STRIPPED, '', $checked_at );
	}

	/**
	 * A result row.
	 *
	 * @param string $status     A STATUS_* constant.
	 * @param string $detail     Why a check could not finish, '' otherwise.
	 * @param string $checked_at When, in UTC.
	 * @return array{status: string, detail: string, checked_at: string}
	 */
	private static function outcome( string $status, string $detail, string $checked_at ): array {
		return array(
			'status'     => $status,
			'detail'     => $detail,
			'checked_at' => $checked_at,
		);
	}

	/**
	 * What to change on the server, as pairs of a sentence and the line to add.
	 *
	 * @return array<int, array{text: string, code: string}>
	 */
	public static function fixes(): array {
		return array(
			array(
				'text' => __( 'Apache: add this line to the .htaccess file in the site’s root folder, above the WordPress rules.', 'zorem-ai-agent-connector' ),
				'code' => 'CGIPassAuth On',
			),
			array(
				'text' => __( 'Apache older than 2.4.13, or where that line is not allowed: add this line instead.', 'zorem-ai-agent-connector' ),
				'code' => 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1',
			),
			array(
				'text' => __( 'Nginx with PHP-FPM: add this line to the block that passes requests to PHP, then reload Nginx.', 'zorem-ai-agent-connector' ),
				'code' => 'fastcgi_param HTTP_AUTHORIZATION $http_authorization;',
			),
		);
	}

	/**
	 * One sentence describing a result, for the admin.
	 *
	 * @param array{status: string, detail: string, checked_at: string} $result A result from result().
	 */
	public static function summary( array $result ): string {
		if ( self::STATUS_PASSED === $result['status'] ) {
			return __( 'Passed on to WordPress. Apps can sign in to the MCP address on this server.', 'zorem-ai-agent-connector' );
		}

		if ( self::STATUS_STRIPPED === $result['status'] ) {
			return __( 'Removed by the web server before WordPress sees it. Apps can complete the sign-in, but every MCP call is then refused as no_credentials, and Claude reports “returned an error when connecting”.', 'zorem-ai-agent-connector' );
		}

		/* translators: %s: why the check could not finish. */
		return sprintf( __( 'Could not be checked: this site could not reach its own REST API (%s). That means we could not look, not that the header is passed on.', 'zorem-ai-agent-connector' ), '' !== $result['detail'] ? $result['detail'] : __( 'no reason given', 'zorem-ai-agent-connector' ) );
	}

	/**
	 * Add the test to Tools → Site Health.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function add_site_health_test( $tests ): array {
		$tests = is_array( $tests ) ? $tests : array();

		$tests['direct']['ai_agent_os_authorization_header'] = array(
			'label' => __( 'AI apps can sign in to the MCP address', 'zorem-ai-agent-connector' ),
			'test'  => array( $this, 'site_health_test' ),
		);

		return $tests;
	}

	/**
	 * The Site Health test result.
	 *
	 * @return array<string, mixed>
	 */
	public function site_health_test(): array {
		$result = $this->result();
		$status = $result['status'];

		$labels = array(
			self::STATUS_PASSED   => __( 'The web server passes the Authorization header to WordPress', 'zorem-ai-agent-connector' ),
			self::STATUS_STRIPPED => __( 'The web server removes the Authorization header, so AI apps cannot use the MCP address', 'zorem-ai-agent-connector' ),
			self::STATUS_UNKNOWN  => __( 'Could not check whether the web server passes the Authorization header', 'zorem-ai-agent-connector' ),
		);

		$severity = array(
			self::STATUS_PASSED   => 'good',
			self::STATUS_STRIPPED => 'critical',
			self::STATUS_UNKNOWN  => 'recommended',
		);

		$description = '<p>' . esc_html( self::summary( $result ) ) . '</p>';

		if ( self::STATUS_STRIPPED === $status ) {
			$description .= '<ul>';

			foreach ( self::fixes() as $fix ) {
				$description .= '<li>' . esc_html( $fix['text'] ) . '<br><code>' . esc_html( $fix['code'] ) . '</code></li>';
			}

			$description .= '</ul><p>' . esc_html__( 'If you cannot edit these files, send this to your hosting provider.', 'zorem-ai-agent-connector' ) . '</p>';
		}

		return array(
			'label'       => $labels[ $status ] ?? $labels[ self::STATUS_UNKNOWN ],
			'status'      => $severity[ $status ] ?? 'recommended',
			'badge'       => array(
				'label' => __( 'Zorem AI Agent Connector', 'zorem-ai-agent-connector' ),
				'color' => 'blue',
			),
			'description' => $description,
			'test'        => 'ai_agent_os_authorization_header',
		);
	}
}
