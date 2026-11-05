<?php
/**
 * Where the connect flow's endpoints live.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Mcp\AgentAddress;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Every URL the connect flow publishes, derived in one place.
 *
 * The issuer carries a path (`/wp-json/ai-agent-os/v1/oauth`) rather than
 * being the bare site origin. That is deliberate: the root documents at
 * `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`
 * belong to whichever plugin claims them first, and on a site running more than
 * one MCP server they already describe somebody else's server. RFC 9728 and
 * RFC 8414 both define path-suffixed locations for exactly this case, and a
 * client follows them in preference to the root.
 */
final class OAuthEndpoints {

	/**
	 * REST sub-route that every OAuth route hangs from.
	 */
	public const BASE = 'oauth';

	/**
	 * Admin page slug of the consent screen.
	 */
	public const CONSENT_PAGE = 'ai-agent-os-connect';

	/**
	 * Why the connect flow cannot run on this site, or null when it can.
	 *
	 * S12 makes HTTPS a MUST for every authorization endpoint, and an issuer
	 * must not carry a query string — which is what plain permalinks turn every
	 * REST URL into. On either, offering the flow would start something that
	 * cannot finish, so it is not offered at all.
	 */
	public static function unavailable_reason(): ?string {
		if ( 'https' !== wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ) {
			return __( 'This site is not served over HTTPS. Clients only sign in to servers that are.', 'zorem-ai-agent-connector' );
		}

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return __( 'This site uses plain permalinks, so its REST URLs carry a query string, which sign-in addresses may not. Choose any other structure under Settings → Permalinks.', 'zorem-ai-agent-connector' );
		}

		return null;
	}

	/**
	 * Whether the connect flow can run on this site.
	 */
	public static function is_available(): bool {
		/*
		 * The same two conditions as unavailable_reason(), without its
		 * messages: this runs on `setup_theme`, before translations may load,
		 * and WordPress warns about text translated that early.
		 */
		return 'https' === wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) && '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * The protected resource: an MCP address, exactly as a client is given it.
	 *
	 * The shared address, or with a code an agent's own address. Each is its
	 * own resource: a client signs in to one, for one agent.
	 *
	 * @param string $code An agent's address code, or '' for the shared address.
	 */
	public static function resource( string $code = '' ): string {
		if ( '' === $code ) {
			return untrailingslashit( Routes::url() );
		}

		return untrailingslashit( self::early_rest_url( Routes::NAMESPACE . '/' . AgentAddress::route( $code ) ) );
	}

	/**
	 * `rest_url()`, also before WordPress has set up `$wp_rewrite`.
	 *
	 * An access token is checked against its resource in `determine_current_user`,
	 * and a plugin may ask for the current user before `$wp_rewrite` exists:
	 * Wordfence does on `plugins_loaded`. `rest_url()` is a fatal error there,
	 * which took every MCP call on mindedmed.com down with a 500. Until then the
	 * URL is built the way `get_rest_url()` builds it, from the same options.
	 *
	 * @param string $path REST route, without a leading slash.
	 */
	private static function early_rest_url( string $path ): string {
		if ( isset( $GLOBALS['wp_rewrite'] ) && $GLOBALS['wp_rewrite'] instanceof \WP_Rewrite ) {
			return rest_url( $path );
		}

		$path      = '/' . ltrim( $path, '/' );
		$structure = (string) get_option( 'permalink_structure' );

		if ( '' === $structure ) {
			$url = add_query_arg( 'rest_route', $path, trailingslashit( home_url( '', 'rest' ) ) . 'index.php' );
		} else {
			// WP_Rewrite::using_index_permalinks(), from the option it reads.
			$index = preg_match( '#^/*index\.php#', $structure ) ? 'index.php/' : '';
			$url   = home_url( $index . rest_get_url_prefix(), 'rest' ) . $path;
		}

		$same_host = is_ssl() && isset( $_SERVER['SERVER_NAME'] ) && wp_parse_url( home_url(), PHP_URL_HOST ) === sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );

		if ( $same_host || ( is_admin() && force_ssl_admin() ) ) {
			$url = set_url_scheme( $url, 'https' );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as rest_url() applies it.
		return apply_filters( 'rest_url', $url, $path, null, 'rest' );
	}

	/**
	 * The agent code of a resource URL, '' for the shared address or anything else.
	 *
	 * @param string $address Resource as a client named it.
	 */
	public static function code_of( string $address ): string {
		$address = strtolower( untrailingslashit( $address ) );
		$prefix  = strtolower( self::resource() ) . '/' . AgentAddress::SEGMENT . '/';

		if ( 0 !== strpos( $address, $prefix ) ) {
			return '';
		}

		$code = substr( $address, strlen( $prefix ) );

		return AgentAddress::is_code( $code ) ? $code : '';
	}

	/**
	 * The authorization server's issuer identifier.
	 */
	public static function issuer(): string {
		return untrailingslashit( rest_url( Routes::NAMESPACE . '/' . self::BASE ) );
	}

	/**
	 * A REST URL below the issuer.
	 *
	 * @param string $path Path below the OAuth base, without a leading slash.
	 */
	public static function url( string $path ): string {
		return rest_url( Routes::NAMESPACE . '/' . self::BASE . '/' . $path );
	}

	/**
	 * Path-suffixed well-known location for the protected resource metadata.
	 *
	 * RFC 9728 §3.1: the well-known segment goes between the host and the
	 * resource's path.
	 *
	 * @param string $code An agent's address code, or '' for the shared address.
	 */
	public static function resource_metadata_path( string $code = '' ): string {
		return '/.well-known/oauth-protected-resource' . self::path_of( self::resource( $code ) );
	}

	/**
	 * Path-suffixed well-known locations for the authorization server metadata.
	 *
	 * RFC 8414 §3.1 for the first; OpenID Connect discovery with path insertion
	 * for the second, because the MCP specification has clients try both.
	 *
	 * @return array<int, string>
	 */
	public static function server_metadata_paths(): array {
		$issuer_path = self::path_of( self::issuer() );

		return array(
			'/.well-known/oauth-authorization-server' . $issuer_path,
			'/.well-known/openid-configuration' . $issuer_path,
		);
	}

	/**
	 * The URL a 401 challenge points a client at: the REST copy of the document.
	 *
	 * Not the well-known location, although RFC 9728 lets either be named.
	 * The well-known path only reaches us when WordPress serves the root of
	 * its host, and on a site with a second MCP server it may not reach us at
	 * all: on staging.todo.today another plugin answered every
	 * `/.well-known/oauth-protected-resource/…` request with its own document,
	 * so a client followed our challenge into somebody else's sign-in. The
	 * REST copy is under this plugin's own namespace, which nothing else
	 * claims. The well-known documents are still served for clients that
	 * look there first.
	 *
	 * @param string $code An agent's address code, or '' for the shared address.
	 */
	public static function resource_metadata_url( string $code = '' ): string {
		return self::url( '' === $code ? 'protected-resource' : 'protected-resource/' . $code );
	}

	/**
	 * The wp-admin URL of the consent screen.
	 */
	public static function consent_url(): string {
		return admin_url( 'admin.php?page=' . self::CONSENT_PAGE );
	}

	/**
	 * The path component of a URL, without a trailing slash.
	 *
	 * @param string $url URL.
	 */
	private static function path_of( string $url ): string {
		return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}
}
