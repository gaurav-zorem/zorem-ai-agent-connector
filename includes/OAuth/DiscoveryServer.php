<?php
/**
 * Well-known documents and the 401 challenge.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Core\Settings;
use AiAgentOs\Mcp\AgentAddress;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a client how to sign in.
 *
 * Two jobs. The MCP endpoint's refusal becomes a challenge that names where
 * the protected-resource metadata lives, and the path-suffixed `/.well-known/`
 * locations answer with the documents themselves.
 *
 * The well-known requests are not REST requests — WordPress would treat them
 * as an unknown front-end URL and render the theme's 404 page. They are
 * answered on `init`, before any of that happens, and only for the exact paths
 * this plugin owns. The root documents (no path suffix) are left alone: they
 * may belong to another plugin on the same site.
 */
final class DiscoveryServer {

	/**
	 * Discovery documents.
	 *
	 * @var OAuthMetadata
	 */
	private OAuthMetadata $metadata;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param OAuthMetadata $metadata Discovery documents.
	 * @param Settings      $settings Plugin settings.
	 */
	public function __construct( OAuthMetadata $metadata, Settings $settings ) {
		$this->metadata = $metadata;
		$this->settings = $settings;
	}

	/**
	 * Hook discovery.
	 */
	public function register(): void {
		if ( ! $this->settings->bool( 'mcp_enabled' ) ) {
			return;
		}

		/*
		 * As early as the documents can be built: `setup_theme` is the first
		 * action after `$wp_rewrite` exists, which `rest_url()` needs. Another
		 * MCP server on the same site may answer every `/.well-known/oauth-…`
		 * request itself, path-suffixed ones included, from `init`; these
		 * paths name this plugin's resource and issuer, so they are ours to
		 * answer first. `init` stays as the fallback for a request that
		 * reaches WordPress some other way.
		 */
		add_action( 'setup_theme', array( $this, 'serve_well_known' ), 0 );
		add_action( 'init', array( $this, 'serve_well_known' ), 1 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_challenge' ), 10, 3 );
	}

	/**
	 * Answer a request for one of our well-known documents, and stop.
	 */
	public function serve_well_known(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';

		if ( false === strpos( $uri, '/.well-known/' ) || ! OAuthEndpoints::is_available() ) {
			return;
		}

		$path = strtolower( untrailingslashit( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) ) );

		// An agent's own address: its document names that address as the resource.
		$agent_prefix = strtolower( OAuthEndpoints::resource_metadata_path() ) . '/' . AgentAddress::SEGMENT . '/';

		if ( 0 === strpos( $path, $agent_prefix ) && AgentAddress::is_code( substr( $path, strlen( $agent_prefix ) ) ) ) {
			$this->send_json( $this->metadata->protected_resource( substr( $path, strlen( $agent_prefix ) ) ) );
		}

		if ( in_array( $path, array_map( 'strtolower', OAuthEndpoints::server_metadata_paths() ), true ) ) {
			$this->send_json( $this->metadata->authorization_server() );
		}
	}

	/**
	 * Turn the MCP endpoint's 401 into an RFC 9728 challenge.
	 *
	 * `error="invalid_token"` is added only when a credential was presented,
	 * which is what tells a client holding an expired access token to refresh
	 * it rather than start the whole flow again. No `scope` (plan D7).
	 *
	 * @param \WP_HTTP_Response $response Result to send.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request used to generate the response.
	 * @return \WP_HTTP_Response
	 */
	public function add_challenge( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		if ( 401 !== $response->get_status() || ! Routes::is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		if ( ! OAuthEndpoints::is_available() ) {
			return $response;
		}

		// An agent's own address points at its own document: the resource is that address.
		$challenge = 'Bearer resource_metadata="' . OAuthEndpoints::resource_metadata_url( AgentAddress::code_in( $request->get_route() ) ) . '"';

		if ( '' !== (string) $request->get_header( 'authorization' ) ) {
			$challenge .= ', error="invalid_token"';
		}

		$response->header( 'WWW-Authenticate', $challenge );
		$response->header( 'Access-Control-Expose-Headers', 'WWW-Authenticate' );

		return $response;
	}

	/**
	 * Emit a discovery document and end the request.
	 *
	 * @param array<string, mixed> $document Document.
	 */
	private function send_json( array $document ): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );

		echo wp_json_encode( $document, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.XSS.EscapeOutput -- JSON document, not HTML.

		exit;
	}
}
