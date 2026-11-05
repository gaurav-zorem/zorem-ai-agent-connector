<?php
/**
 * Loader for the bundled MCP Adapter fallback copy.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a PSR-4 autoloader for WP\MCP\ pointing at our bundled copy.
 *
 * The adapter ships its own Autoloader class, but that one expects the
 * standalone plugin layout (a Composer vendor/autoload.php and a WP_MCP_DIR
 * constant). Our bundled copy is just the includes/ tree, so we map the
 * namespace ourselves — WP\MCP\ => vendor-bundled/mcp-adapter/includes/, which
 * is the mapping the adapter's own composer.json declares.
 *
 * This only ever runs when no other copy has already been loaded — chiefly
 * WooCommerce's vendored copy, which wins by virtue of loading first.
 */
final class BundledAdapterLoader {

	private const PREFIX = 'WP\\MCP\\';

	/**
	 * Whether the fallback autoloader has been registered with SPL.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register the fallback autoloader if the bundled copy is present.
	 *
	 * @return bool Whether a bundled copy is available to load from.
	 */
	public static function register(): bool {
		if ( self::$registered ) {
			return true;
		}

		if ( ! is_dir( self::base_dir() ) ) {
			return false;
		}

		spl_autoload_register( array( self::class, 'load' ) );
		self::$registered = true;

		return true;
	}

	/**
	 * Absolute path to the bundled adapter's includes/ directory.
	 */
	public static function base_dir(): string {
		return AI_AGENT_OS_PATH . 'vendor-bundled/mcp-adapter/includes/';
	}

	/**
	 * Autoload a WP\MCP\ class from the bundled copy.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$path     = self::base_dir() . $relative . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		require_once $path;
	}
}
