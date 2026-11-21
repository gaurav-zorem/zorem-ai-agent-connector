<?php
/**
 * The capability that manages this plugin.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * `manage_agent_os`: who may configure the agent and knowledge.
 *
 * A custom capability rather than `manage_options`, so a store can hand agent
 * administration to a Shop Manager without handing over the whole site
 * (decision D-7). Granted to administrators on activation.
 *
 * The name is not private to this plugin: anything else that administers
 * the agent on the same site may use it too. Uninstalling therefore asks, through
 * `ai_agent_os_keep_manage_capability`, before taking it away, so removing one
 * plugin never locks administrators out of a screen that is still there.
 */
final class Capabilities {

	/**
	 * The capability name, which other plugins may share.
	 */
	public const MANAGE = 'manage_agent_os';

	/**
	 * Roles that receive the capability on activation.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULT_ROLES = array( 'administrator' );

	/**
	 * Grant the capability to the default roles.
	 */
	public static function grant(): void {
		foreach ( self::DEFAULT_ROLES as $role_name ) {
			$role = get_role( $role_name );

			if ( $role instanceof \WP_Role && ! $role->has_cap( self::MANAGE ) ) {
				$role->add_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Remove the capability from every role.
	 */
	public static function revoke(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE ) ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}
}
