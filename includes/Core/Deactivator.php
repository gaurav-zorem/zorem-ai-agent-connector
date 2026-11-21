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
 * only uninstall removes anything — and then only when opted in. The one
 * exception is the agents' application passwords: once the plugin is
 * inactive nothing confines an agent's account to the agent doors, so a
 * password left on it would work on the whole REST API and XML-RPC.
 */
final class Deactivator {

	/**
	 * Deactivate: on this site, or on every site of a network.
	 *
	 * @param bool $network_wide Whether deactivated across a network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		Activator::on_each_site( $network_wide, array( Cron::class, 'unschedule' ) );

		// User meta is shared by every site of a network, so once is enough.
		self::drop_agent_application_passwords();
	}

	/**
	 * Delete the application passwords on every agent's account.
	 *
	 * Agents connect with their own tokens, which stop working with the
	 * plugin; an application password would not. Accounts are found by the
	 * agent link in user meta, read directly because the plugin hides agent
	 * accounts from user queries. Reactivating does not bring them back:
	 * whoever needs one creates a new one.
	 */
	public static function drop_agent_application_passwords(): void {
		if ( ! class_exists( \WP_Application_Passwords::class ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runs once, on deactivation; a user query would skip the hidden agent accounts.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i WHERE meta_key = %s', $wpdb->usermeta, \AiAgentOs\Agents\ShadowUserManager::AGENT_META_KEY ) );

		foreach ( array_map( 'intval', (array) $ids ) as $user_id ) {
			if ( $user_id > 0 ) {
				\WP_Application_Passwords::delete_all_application_passwords( $user_id );
			}
		}
	}
}
