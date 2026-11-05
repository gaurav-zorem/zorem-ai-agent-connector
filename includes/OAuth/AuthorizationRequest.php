<?php
/**
 * One validated authorization request.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

defined( 'ABSPATH' ) || exit;

/**
 * Parses and validates the parameters of an authorization request.
 *
 * The same validation runs when the consent screen is shown and again when
 * it is submitted, because the submission is just another request carrying
 * the same parameters and nothing about having rendered a page earlier makes
 * them trustworthy.
 *
 * Errors come in two kinds, and the difference is a security property. Until
 * the client and its redirect URI are verified, an error is shown on this
 * site and nothing is sent anywhere — redirecting to an unverified address is
 * an open redirect. After that, errors go back to the client.
 */
final class AuthorizationRequest {

	/**
	 * The parameters carried through the consent screen.
	 *
	 * `scope` is not among them. This server publishes no `scopes_supported`
	 * (plan S17 / D7), so a scope has no meaning here: it is not read, not
	 * stored and not echoed back. Accepting it only to carry an unvalidated
	 * string through a form and a redirect would be carrying it for nothing.
	 */
	public const PARAMS = array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'resource' );

	/**
	 * How each parameter is cleaned as it comes in, before anything looks at it.
	 *
	 * Sanitising is not validating and neither is enough alone, so both happen:
	 * every parameter is cleaned here by its kind, and `from_params()` then
	 * refuses the request unless each one is a value this server can act on.
	 * The two that are only ever handed back to the client — `redirect_uri`,
	 * matched against what the client published, and `state`, round-tripped
	 * verbatim — are cleaned the same way as the rest.
	 *
	 * @var array<string, string>
	 */
	private const SANITIZERS = array(
		'response_type'         => 'sanitize_key',
		'client_id'             => 'sanitize_text_field',
		'redirect_uri'          => 'sanitize_url',
		'code_challenge'        => 'sanitize_text_field',

		/*
		 * Not `sanitize_key()`, which lowercases: the method is `S256`, spelt
		 * that way in RFC 7636 and compared exactly below, so lowercasing it
		 * refused every correct request and broke sign-in outright.
		 */
		'code_challenge_method' => 'sanitize_text_field',
		'state'                 => 'sanitize_text_field',
		'resource'              => 'sanitize_url',
	);

	/**
	 * The longest any one parameter may be.
	 *
	 * A client id is a URL in the CIMD flow and `state` is opaque to this
	 * server, so neither has a natural shape to check. A ceiling is still a
	 * check: it bounds what a stranger can make this site hold in memory, put
	 * in a form and write into a redirect.
	 */
	private const MAX_LENGTH = 1024;

	/**
	 * The resolved client.
	 *
	 * @var array<string, mixed>
	 */
	private array $client;

	/**
	 * The parameters, as received.
	 *
	 * @var array<string, string>
	 */
	private array $params;

	/**
	 * Construct from an already-validated request.
	 *
	 * @param array<string, mixed>  $client Resolved client.
	 * @param array<string, string> $params Parameters.
	 */
	private function __construct( array $client, array $params ) {
		$this->client = $client;
		$this->params = $params;
	}

	/**
	 * Validate raw parameters.
	 *
	 * @param array<string, mixed> $raw      Request parameters.
	 * @param ClientResolver       $resolver Client identification.
	 * @return AuthorizationRequest|\WP_Error A WP_Error carries `redirect_uri`
	 *         in its data only when it is safe to send the error to the client.
	 */
	public static function from_params( array $raw, ClientResolver $resolver ) {
		$params = array();

		foreach ( self::PARAMS as $key ) {
			$value = isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ? (string) $raw[ $key ] : '';

			if ( '' !== $value ) {
				$sanitizer = self::SANITIZERS[ $key ];
				$value     = (string) call_user_func( $sanitizer, $value );
			}

			$params[ $key ] = mb_substr( $value, 0, self::MAX_LENGTH );
		}

		$client = $resolver->resolve( $params['client_id'] );

		if ( is_wp_error( $client ) ) {
			return $client;
		}

		/*
		 * Exact string match against what the client published (S7). No
		 * prefix match, no normalisation: a redirect URI that differs by one
		 * character is a different destination.
		 */
		if ( '' === $params['redirect_uri'] || ! in_array( $params['redirect_uri'], $client['redirect_uris'], true ) ) {
			return new \WP_Error(
				'ai_agent_os_oauth_invalid_redirect',
				__( 'The client asked to be sent back to an address it has not published. Nothing was sent.', 'zorem-ai-agent-connector' )
			);
		}

		$request = new self( $client, $params );

		// From here on the redirect URI is verified, so errors go back to the client.
		if ( 'code' !== $params['response_type'] ) {
			return $request->client_error( 'unsupported_response_type', 'Only the authorization code flow is supported.' );
		}

		/*
		 * PKCE is required, and only S256 (S3). A missing method means
		 * `plain`, which is a downgrade, so it is refused rather than assumed.
		 */
		if ( 'S256' !== $params['code_challenge_method'] || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/', $params['code_challenge'] ) ) {
			return $request->client_error( 'invalid_request', 'PKCE with code_challenge_method=S256 is required.' );
		}

		if ( ! self::is_our_resource( $params['resource'] ) ) {
			return $request->client_error( 'invalid_target', 'Sign in at an agent’s own MCP address. Copy it from the agent’s Connection section on this site.' );
		}

		return $request;
	}

	/**
	 * Whether a `resource` value names one of this server's MCP addresses (S6):
	 * an agent's own, shaped as one. There is no shared address.
	 *
	 * @param string $candidate Requested resource.
	 */
	public static function is_our_resource( string $candidate ): bool {
		return '' !== OAuthEndpoints::code_of( $candidate );
	}

	/**
	 * The agent code of the requested resource: the agent whose own address the client signs in to.
	 */
	public function agent_code(): string {
		return OAuthEndpoints::code_of( $this->param( 'resource' ) );
	}

	/**
	 * The resource the approval is for: the agent's own address.
	 */
	public function resource(): string {
		return OAuthEndpoints::resource( $this->agent_code() );
	}

	/**
	 * The resolved client.
	 *
	 * @return array<string, mixed>
	 */
	public function client(): array {
		return $this->client;
	}

	/**
	 * One parameter.
	 *
	 * @param string $key Parameter name.
	 */
	public function param( string $key ): string {
		return $this->params[ $key ] ?? '';
	}

	/**
	 * Every parameter, for carrying through a form.
	 *
	 * @return array<string, string>
	 */
	public function params(): array {
		return $this->params;
	}

	/**
	 * The host a code will be delivered to — the one fact on the consent
	 * screen the client cannot choose freely (S8).
	 */
	public function redirect_host(): string {
		return strtolower( (string) wp_parse_url( $this->params['redirect_uri'], PHP_URL_HOST ) );
	}

	/**
	 * Whether the code goes to this machine rather than a hosted service (S9).
	 */
	public function is_loopback(): bool {
		return ClientResolver::is_loopback_host( $this->redirect_host() );
	}

	/**
	 * The client's redirect URI with response parameters appended.
	 *
	 * `iss` is always included (S5, RFC 9207), and `state` is round-tripped.
	 *
	 * @param array<string, string> $args Response parameters.
	 */
	public function redirect_with( array $args ): string {
		$args['iss'] = OAuthEndpoints::issuer();

		if ( '' !== $this->params['state'] ) {
			$args['state'] = $this->params['state'];
		}

		/*
		 * `add_query_arg()` encodes what it is given. Encoding first as well
		 * sent the client `state=a%2Bb` for a state of `a+b` — a value that no
		 * longer matches the one it generated, which is the whole use of
		 * `state` as a CSRF defence.
		 */
		return add_query_arg( $args, $this->params['redirect_uri'] );
	}

	/**
	 * An error that may be sent back to the client.
	 *
	 * @param string $error       OAuth error code.
	 * @param string $description Description for the client's developer.
	 */
	public function client_error( string $error, string $description ): \WP_Error {
		return new \WP_Error(
			'ai_agent_os_oauth_' . $error,
			$description,
			array(
				'oauth_error'  => $error,
				'redirect_uri' => $this->redirect_with(
					array(
						'error'             => $error,
						'error_description' => $description,
					)
				),
			)
		);
	}
}
