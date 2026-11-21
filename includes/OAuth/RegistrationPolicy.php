<?php
/**
 * Which apps may register themselves to sign in.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Core\Settings;

/**
 * Dynamic Client Registration (RFC 7591), open only to known apps.
 *
 * Claude and ChatGPT identify themselves with a client metadata document and
 * never register. Grok's connectors (grok.com) can only sign in by
 * registering first, so registration came back in 1.0.3 for Grok alone: a
 * registration is accepted only when every callback address it lists is on
 * an allowed host. Open registration would let anyone create an app named
 * "Claude" whose callback is their own server and send an administrator a
 * sign-in link; with the allow-list a registered app can only ever send the
 * sign-in back to Grok.
 *
 * Every sign-in still needs an administrator to approve it on the consent
 * screen; registering grants nothing.
 */
final class RegistrationPolicy {

	/** The setting that switches registration on (Settings → General). */
	public const SETTING = 'oauth_grok_registration';

	/**
	 * Hosts whose callback addresses may register, each with its subdomains.
	 *
	 * Grok (xAI): grok.com and x.ai.
	 */
	private const HOSTS = array( 'grok.com', 'x.ai' );

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether registration is switched on.
	 */
	public function is_enabled(): bool {
		return $this->settings->bool( self::SETTING );
	}

	/**
	 * Hosts whose callbacks may register (each also covers its subdomains).
	 *
	 * @return array<int, string>
	 */
	public static function hosts(): array {
		/**
		 * Filters the hosts whose callback addresses may register to sign in.
		 *
		 * Each host also covers its subdomains. Add one only for an app that
		 * cannot sign in with a client metadata document.
		 *
		 * @param array<int, string> $hosts Allowed hosts, e.g. 'grok.com'.
		 */
		$hosts = (array) apply_filters( 'ai_agent_os_oauth_registration_hosts', self::HOSTS );

		return array_values( array_filter( array_map( static fn( $host ): string => strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) ), $hosts ) ) );
	}

	/**
	 * Whether a callback address may be registered: https, on an allowed host.
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function allows_redirect( string $uri ): bool {
		if ( ! ClientResolver::is_allowed_redirect( $uri ) ) {
			return false;
		}

		$parts = wp_parse_url( $uri );

		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return false;
		}

		$host = strtolower( (string) ( $parts['host'] ?? '' ) );

		foreach ( self::hosts() as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The host of a callback address, for the log ('' when it has none).
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function host_of( string $uri ): string {
		return strtolower( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
	}
}
