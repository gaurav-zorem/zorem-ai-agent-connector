<?php
/**
 * Agent Runtime Bridge — version registrar and loader.
 *
 * Every plugin that bundles the bridge requires this file from its own copy
 * under `lib/zorem-agent-bridge/`. Each copy announces its version; on
 * `plugins_loaded` priority 1 exactly one copy — the highest version — has its
 * classes autoloaded. Two plugins bundling 1.0.0 and 1.1.0 therefore both run
 * against 1.1.0, and neither needs to know the other exists.
 *
 * The functions below are defined by whichever copy loads first and are the
 * one part of the bridge that cannot be upgraded by a newer copy. They are
 * kept deliberately small for that reason: pick a version, load it, announce
 * it. Everything else lives in `src/`, which the winning copy provides.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zorem_agent_bridge_copies' ) ) {
	/**
	 * The bridge copies announced so far, as directory => version.
	 *
	 * Held in a static rather than a global so no other code can write to it.
	 *
	 * @param string|null $version Version to record, or null to only read.
	 * @param string|null $dir     Directory of the copy being recorded.
	 * @return array<string, string>
	 */
	function zorem_agent_bridge_copies( ?string $version = null, ?string $dir = null ): array {
		static $copies = array();

		if ( null !== $version && null !== $dir && ! did_action( 'zorem_agent_bridge_loaded' ) ) {
			$copies[ wp_normalize_path( $dir ) ] = $version;
		}

		return $copies;
	}

	/**
	 * The directory of the copy that should load: the highest version.
	 *
	 * Ties go to the first copy announced, so the choice is stable for a given
	 * plugin load order. Pure, so it can be tested without loading WordPress
	 * plugins in a particular order.
	 *
	 * @param array<string, string> $copies Directory => version.
	 */
	function zorem_agent_bridge_pick( array $copies ): ?string {
		$best_dir     = null;
		$best_version = null;

		foreach ( $copies as $dir => $version ) {
			if ( null === $best_version || version_compare( $version, $best_version, '>' ) ) {
				$best_dir     = (string) $dir;
				$best_version = $version;
			}
		}

		return $best_dir;
	}

	/**
	 * Load the winning copy and announce it.
	 *
	 * Fires `zorem_agent_bridge_loaded` exactly once, after which no further
	 * copy can change the choice.
	 */
	function zorem_agent_bridge_load(): void {
		if ( did_action( 'zorem_agent_bridge_loaded' ) ) {
			return;
		}

		$copies = zorem_agent_bridge_copies();
		$dir    = zorem_agent_bridge_pick( $copies );

		if ( null === $dir || ! is_readable( $dir . '/src/autoload.php' ) ) {
			return;
		}

		define( 'ZOREM_AGENT_BRIDGE_VERSION', $copies[ $dir ] );
		define( 'ZOREM_AGENT_BRIDGE_DIR', trailingslashit( $dir ) );

		require_once $dir . '/src/autoload.php';

		/**
		 * Fires once, after the chosen bridge copy is loaded.
		 *
		 * @param string                $version The version that loaded.
		 * @param array<string, string> $copies  Every copy announced, directory => version.
		 */
		do_action( 'zorem_agent_bridge_loaded', ZOREM_AGENT_BRIDGE_VERSION, $copies );
	}

	// Priority 1: after every plugin file has announced its copy, before any plugin boots at 5.
	add_action( 'plugins_loaded', 'zorem_agent_bridge_load', 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this copy's own VERSION file, before WP_Filesystem exists.
zorem_agent_bridge_copies( trim( (string) file_get_contents( __DIR__ . '/VERSION' ) ), __DIR__ );
