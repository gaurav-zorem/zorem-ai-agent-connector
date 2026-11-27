<?php
/**
 * Activation.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Work done once, when the plugin is activated.
 *
 * Activation succeeds even while the legacy plugin is active: refusing it
 * would leave an administrator unable to install the new plugin before
 * switching over. The plugin then stays inert at boot and says why.
 */
final class Activator {

	/**
	 * Option recording the first activation time.
	 */
	public const INSTALL_TS_OPTION = 'ai_agent_os_install_ts';

	/**
	 * Activate: on this site, or on every site of a network.
	 *
	 * @param bool $network_wide Whether activated across a network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::on_each_site( $network_wide, array( self::class, 'activate_site' ) );
	}

	/**
	 * Run a callback on every site of the network when network-wide, else here.
	 *
	 * @param bool     $network_wide Whether across the network.
	 * @param callable $callback     Work for one site.
	 */
	public static function on_each_site( bool $network_wide, callable $callback ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$callback();

			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );

			try {
				$callback();
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Hook the network's site lifecycle: a site created while the plugin is
	 * network-active is set up like the others, and a deleted site's tables go
	 * with it.
	 */
	public static function register_network_hooks(): void {
		add_action( 'wp_initialize_site', array( self::class, 'initialize_site' ), 200 );
		add_filter( 'wpmu_drop_tables', array( self::class, 'site_tables' ) );
	}

	/**
	 * Set up a new site of a network on which the plugin is network-active.
	 *
	 * @param \WP_Site $site The new site.
	 */
	public static function initialize_site( $site ): void {
		if ( ! $site instanceof \WP_Site ) {
			return;
		}

		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		if ( ! is_plugin_active_for_network( AI_AGENT_OS_BASENAME ) ) {
			return;
		}

		switch_to_blog( (int) $site->blog_id );

		try {
			self::activate_site();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Add this plugin's tables to the ones dropped with a deleted site.
	 *
	 * WordPress has switched to the site being deleted, so the tables carry its prefix.
	 *
	 * @param array<int, string> $tables Tables to drop.
	 * @return array<int, string>
	 */
	public static function site_tables( $tables ): array {
		$tables = (array) $tables;

		foreach ( Installer::all_tables() as $basename ) {
			$tables[] = Installer::table( $basename );
		}

		return array_values( array_unique( $tables ) );
	}

	/**
	 * Set up the current site.
	 */
	public static function activate_site(): void {
		Capabilities::grant();
		Installer::install();

		/*
		 * The shared Website Brain store. Activation runs after plugins_loaded,
		 * often with no other Agent OS plugin active, so the kit may not be
		 * loaded yet: load the best bundled copy now. Creating the store here,
		 * not on first use, means a second plugin activated later always finds it.
		 */
		if ( function_exists( 'zorem_agent_brain_load' ) ) {
			zorem_agent_brain_load();
		}

		if ( class_exists( \ZoremAgentBrain\V1\Brain::class ) && ! Coexistence::legacy_is_active() ) {
			\ZoremAgentBrain\V1\Brain::activate_host( 'ai-agent-os', AI_AGENT_OS_VERSION, AI_AGENT_OS_BASENAME );
		}

		$first_install = false === get_option( self::INSTALL_TS_OPTION );

		// The Admin Assistant agent a new site starts with; made on the next admin page.
		\AiAgentOs\Agents\SiteAgent::mark_due( $first_install );

		if ( $first_install ) {
			add_option( self::INSTALL_TS_OPTION, time(), '', false );
		}
	}
}
