<?php
/**
 * Uninstall routine.
 *
 * Plugin data is destroyed only when the administrator opted in with the
 * "Delete data on uninstall" setting. The `manage_agent_os` capability is
 * removed unless something else on the site asks to keep it, through the
 * filter below, so uninstalling this plugin never locks administrators out of
 * a screen that is still there.
 *
 * Whatever the setting, scheduled tasks stop and every connect-flow credential
 * (access and refresh tokens) is deleted: nothing is left that could act once
 * the plugin is gone. On a network, every site is cleaned the same way.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/zorem-ai-agent-connector.php';

/**
 * Filters whether this plugin's data survives its uninstall.
 *
 * Another active plugin may share this agent, its tokens, settings and tables.
 * Where one does, it returns true here and nothing below runs, so removing this
 * plugin does not take the other plugin's data with it.
 *
 * @param bool $preserve Whether to leave the data alone. Default false.
 */
if ( (bool) apply_filters( 'ai_agent_os_preserve_data_on_uninstall', false ) ) {
	return;
}

/**
 * Uninstall from the current site.
 */
function ai_agent_os_uninstall_site(): void {
	global $wpdb;

	\AiAgentOs\Core\Cron::unschedule();

	/**
	 * Filters whether `manage_agent_os` survives this uninstall.
	 *
	 * The capability is not private to this plugin: anything else that uses it
	 * to decide who may administer the agent would lock administrators out if it
	 * disappeared from under them. Such a plugin returns true here instead of
	 * this plugin naming it.
	 *
	 * @param bool $keep Whether to leave the capability in place. Default false.
	 */
	if ( ! (bool) apply_filters( 'ai_agent_os_keep_manage_capability', false ) ) {
		\AiAgentOs\Core\Capabilities::revoke();
	}

	// Credentials go even when the data stays: an access or refresh token outliving the plugin is a door left open.
	$ai_agent_os_tokens = \AiAgentOs\Core\Installer::table( \AiAgentOs\Core\Installer::TABLE_OAUTH_TOKENS );
	$ai_agent_os_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ai_agent_os_tokens ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall context.

	if ( $ai_agent_os_exists === $ai_agent_os_tokens ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $ai_agent_os_tokens ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall context.
	}

	$ai_agent_os_settings = get_option( \AiAgentOs\Core\Settings::OPTION, array() );

	if ( ! is_array( $ai_agent_os_settings ) || empty( $ai_agent_os_settings['delete_data_on_uninstall'] ) ) {
		return;
	}

	\AiAgentOs\Core\Installer::remove_agent_users();
	\AiAgentOs\Core\Installer::drop_tables();

	/*
	 * Options and transients by prefix rather than from a list: a list is a second
	 * place every new option has to be remembered. Everything this plugin stores is
	 * under `ai_agent_os_`, a prefix it alone uses.
	 */
	foreach (
		array(
			'ai_agent_os_',
			'_transient_ai_agent_os_',
			'_transient_timeout_ai_agent_os_',
		) as $ai_agent_os_prefix
	) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uninstall context; no API deletes options by prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $ai_agent_os_prefix ) . '%'
			)
		);
	}

	wp_cache_flush();
}

\AiAgentOs\Core\Activator::on_each_site( is_multisite(), 'ai_agent_os_uninstall_site' );
