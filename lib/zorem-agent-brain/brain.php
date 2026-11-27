<?php
/**
 * Website Brain Kit — version registrar and loader.
 *
 * Every Agent OS plugin bundles the Brain Kit under `lib/zorem-agent-brain/`
 * and requires this file from its own copy. Each copy announces its version;
 * on `plugins_loaded` priority 1 exactly one copy — the highest — is
 * autoloaded, so two plugins bundling different versions share one Brain
 * engine against one store.
 *
 * Unlike the bridge, the kit can also be loaded late, on demand: activation and
 * uninstall run after `plugins_loaded`, often with no other Agent OS plugin
 * active, and they need the kit to create or remove the shared store. Calling
 * zorem_agent_brain_load() then loads the best copy announced so far.
 *
 * The functions here are defined by whichever copy loads first and cannot be
 * upgraded by a newer copy, so they stay minimal: pick, load, announce.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zorem_agent_brain_copies' ) ) {
	/**
	 * The Brain Kit copies announced so far, as directory => version.
	 *
	 * @param string|null $version Version to record, or null to only read.
	 * @param string|null $dir     Directory of the copy being recorded.
	 * @return array<string, string>
	 */
	function zorem_agent_brain_copies( ?string $version = null, ?string $dir = null ): array {
		static $copies = array();

		if ( null !== $version && null !== $dir && ! defined( 'ZOREM_AGENT_BRAIN_VERSION' ) ) {
			$copies[ wp_normalize_path( $dir ) ] = $version;
		}

		return $copies;
	}

	/**
	 * The directory of the highest version; ties keep the first announced.
	 *
	 * @param array<string, string> $copies Directory => version.
	 */
	function zorem_agent_brain_pick( array $copies ): ?string {
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
	 * Load the winning copy, once. Safe to call again, and safe to call late.
	 */
	function zorem_agent_brain_load(): void {
		if ( defined( 'ZOREM_AGENT_BRAIN_VERSION' ) ) {
			return;
		}

		$copies = zorem_agent_brain_copies();
		$dir    = zorem_agent_brain_pick( $copies );

		if ( null === $dir || ! is_readable( $dir . '/src/autoload.php' ) ) {
			return;
		}

		define( 'ZOREM_AGENT_BRAIN_VERSION', $copies[ $dir ] );
		define( 'ZOREM_AGENT_BRAIN_DIR', trailingslashit( $dir ) );

		require_once $dir . '/src/autoload.php';

		/**
		 * Fires once, after the chosen Brain Kit copy is loaded.
		 *
		 * @param string                $version The version that loaded.
		 * @param array<string, string> $copies  Every copy announced, directory => version.
		 */
		do_action( 'zorem_agent_brain_loaded', ZOREM_AGENT_BRAIN_VERSION, $copies );
	}

	// Priority 1: after every plugin file has announced its copy, before any plugin boots at 5.
	add_action( 'plugins_loaded', 'zorem_agent_brain_load', 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads this copy's own VERSION file, before WP_Filesystem exists.
zorem_agent_brain_copies( trim( (string) file_get_contents( __DIR__ . '/VERSION' ) ), __DIR__ );
