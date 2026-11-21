<?php
/**
 * Detection of the earlier all-in-one plugin this one replaces.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one question: is the legacy `zorem-agent-os` plugin active?
 *
 * The legacy plugin wraps every ability on the site in its own permission
 * gate, and so does this plugin. Two gates would mean two permission
 * authorities, each able to allow what the other denies, with no single place
 * to ask why the agent was refused. So while the legacy plugin runs, this one
 * stays inert (decision D-5 in the split plan).
 *
 * Two signals, either of which is enough:
 *
 * 1. The legacy plugin's version constant. Every active plugin's main file is
 *    included before `plugins_loaded`, so by the time this is asked the
 *    constant exists if the plugin loaded — whatever its folder is called.
 * 2. The active-plugins option, including network activation, which also
 *    covers the activation request itself, before the plugin has loaded.
 */
final class Coexistence {

	/**
	 * The legacy plugin's basename in its standard folder.
	 */
	public const LEGACY_BASENAME = 'zorem-agent-os/zorem-agent-os.php';

	/**
	 * Constant the legacy plugin defines when its main file loads.
	 */
	public const LEGACY_CONSTANT = 'ZOREM_AGENTOS_VERSION';

	/**
	 * Whether the legacy plugin is active on this site.
	 */
	public static function legacy_is_active(): bool {
		if ( defined( self::LEGACY_CONSTANT ) ) {
			return true;
		}

		if ( in_array( self::LEGACY_BASENAME, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );

			if ( isset( $network[ self::LEGACY_BASENAME ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What to call the conflicting plugin in a notice.
	 *
	 * Read from its own header rather than written down here: an administrator
	 * has to find it in the plugins list, and the only name that will match
	 * what they see there is the one WordPress shows them. A site where the
	 * file is missing or unreadable falls back to the folder name.
	 */
	public static function legacy_plugin_name(): string {
		$file = WP_PLUGIN_DIR . '/' . self::LEGACY_BASENAME;

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( is_readable( $file ) ) {
			$name = (string) ( get_plugin_data( $file, false, false )['Name'] ?? '' );

			if ( '' !== $name ) {
				return $name;
			}
		}

		return dirname( self::LEGACY_BASENAME );
	}
}
