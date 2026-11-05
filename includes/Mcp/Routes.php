<?php
/**
 * MCP route and server identifiers.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Where our MCP endpoint lives.
 *
 * One stable URL, with the bearer token deciding what — and therefore which
 * tools — the request sees. A URL built from the agent's name would put that
 * name in the REST index for anyone probing it, and would make client
 * configuration churn whenever the name changed.
 */
final class Routes {

	/**
	 * Server id registered with the MCP adapter.
	 */
	public const SERVER_ID = 'ai-agent-os';

	/**
	 * REST namespace.
	 */
	public const NAMESPACE = 'ai-agent-os/v1';

	/**
	 * REST route within the namespace.
	 */
	public const ROUTE = 'mcp';

	/**
	 * Namespace for the plugin's admin REST controllers.
	 */
	public const ADMIN_NAMESPACE = 'ai-agent-os/v1';

	/**
	 * The MCP endpoint path, without the site or REST prefix.
	 */
	public static function path(): string {
		return '/' . self::NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * The full public URL of the MCP endpoint.
	 */
	public static function url(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Whether the current request targets a route governed by agent tokens.
	 *
	 * Agent tokens are accepted only here. Without this check a leaked token
	 * would authenticate against the whole REST API, which would hand the
	 * bearer everything the shadow user can reach and defeat the point of
	 * scoping permissions per ability.
	 */
	public static function is_governed_request(): bool {
		return self::is_route( self::requested_route(), strtolower( self::path() ) );
	}

	/**
	 * The agent code in this request's address, when it is an agent's own address; otherwise ''.
	 *
	 * Read from the request URL as WordPress will route it, before dispatch.
	 */
	public static function requested_agent_code(): string {
		return AgentAddress::code_in( self::requested_route() );
	}

	/**
	 * Whether a REST route is one of this plugin's MCP addresses: an agent's own.
	 *
	 * @param string $route REST route.
	 */
	public static function is_mcp_route( string $route ): bool {
		$route = strtolower( untrailingslashit( $route ) );

		return '' !== AgentAddress::code_in( $route );
	}

	/**
	 * The REST route this request names, lowercased, or '' when it is not a REST request.
	 *
	 * Read from the request path itself. It used to be enough for the MCP path
	 * to appear anywhere in the URI, so a query string such as
	 * `?x=/wp-json/ai-agent-os/v1/mcp` made a bearer token log its agent in
	 * on any URL: another REST route, admin-ajax, the front end. Lowercased
	 * because WordPress matches routes case-insensitively.
	 *
	 * Runs at `determine_current_user`, before WordPress has parsed the
	 * request, so it parses the URL the same way WordPress will.
	 */
	public static function requested_route(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path = strtolower( rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) ) );
		$home = strtolower( rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' ) );

		/*
		 * `rest_route` from the request, in WordPress's own order. WP::parse_request()
		 * takes it from POST, then GET, and only then from the permalink, so a
		 * parameter beats the path: `/wp-json/ai-agent-os/v1/mcp?rest_route=/wp/v2/users/me`
		 * is dispatched to `/wp/v2/users/me`. Reading the path alone let an agent
		 * credential authenticate on any REST route that way.
		 */
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.CSRF.NonceVerification -- Read-only inspection of the request target, not state-changing.
		$raw_param = null;

		foreach ( array( $_POST, $_GET ) as $source ) {
			if ( isset( $source['rest_route'] ) && is_scalar( $source['rest_route'] ) ) {
				$raw_param = sanitize_text_field( wp_unslash( (string) $source['rest_route'] ) );
				break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification, WordPress.CSRF.NonceVerification

		$route_param = null === $raw_param ? '' : strtolower( $raw_param );
		$is_rest     = in_array( $path, array( $home . '/', $home . '/index.php', $home ), true );
		$from_path   = '';

		foreach ( array( $home . '/' . rest_get_url_prefix(), $home . '/index.php/' . rest_get_url_prefix() ) as $base ) {
			if ( substr( $path, 0, strlen( $base ) + 1 ) === $base . '/' ) {
				$is_rest   = true;
				$from_path = substr( $path, strlen( $base ) );
				break;
			}
		}

		// Only the front controller and the REST prefix serve REST: elsewhere (admin-ajax, wp-login) nothing is routed.
		if ( ! $is_rest ) {
			return '';
		}

		if ( null !== $raw_param ) {
			return '' === $route_param ? '' : '/' . ltrim( $route_param, '/' );
		}

		return $from_path;
	}

	/**
	 * Whether a REST route is the given route, or below it.
	 *
	 * @param string $candidate The route the request names.
	 * @param string $route     The route.
	 */
	private static function is_route( string $candidate, string $route ): bool {
		return '' !== $candidate && ( $candidate === $route || 0 === strpos( $candidate, $route . '/' ) );
	}
}
