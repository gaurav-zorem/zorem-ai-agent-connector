<?php
/**
 * Deactivation.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Work done when the plugin is deactivated.
 *
 * Deactivation keeps data and capabilities: it is routinely temporary, and
 * only uninstall removes anything — and then only when opted in.
 */
final class Deactivator {

	/**
	 * Deactivate: on this site, or on every site of a network.
	 *
	 * @param bool $network_wide Whether deactivated across a network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		Activator::on_each_site( $network_wide, array( Cron::class, 'unschedule' ) );
	}
}
