<?php
/**
 * PSR-4 autoloader for the bridge classes of the copy that won version selection.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'ZoremAgentBridge\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$path     = __DIR__ . '/' . $relative . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

// Only the winning copy reaches this line, so the bridge's hooks are added exactly once.
\ZoremAgentBridge\V1\Bridge::boot();
