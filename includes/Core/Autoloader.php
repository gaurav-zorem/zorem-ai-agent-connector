<?php
/**
 * PSR-4 autoloader.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps `AiAgentOs\` to `includes/`.
 *
 * The plugin has no runtime Composer dependencies, so it ships its own loader
 * instead of requiring `vendor/autoload.php`.
 */
final class Autoloader {

	/**
	 * Namespace prefix this loader handles.
	 */
	private const PREFIX = 'AiAgentOs\\';

	/**
	 * Register with SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to this plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class_name, strlen( self::PREFIX ) ) );
		$base     = realpath( AI_AGENT_OS_PATH . 'includes' );
		$real     = realpath( AI_AGENT_OS_PATH . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php' );

		// A resolved path must stay inside includes/, so `..` in a class name cannot load other files.
		if ( false === $real || false === $base || 0 !== strpos( $real, $base ) ) {
			return;
		}

		require_once $real;
	}
}
