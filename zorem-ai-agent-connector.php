<?php
/**
 * Plugin Name:       Zorem AI Agent Connector
 * Plugin URI:        https://wordpress.org/plugins/zorem-ai-agent-connector/
 * Description:       Connect Claude, ChatGPT and other AI apps to WordPress safely: an AI agent with its own identity and exact permissions, on top of the WordPress Abilities API and the official MCP Adapter.
 * Version:           1.4
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * WC requires at least: 5.0
 * WC tested up to: 11.3.0
 * Author:            zorem
 * Author URI:        https://www.zorem.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zorem-ai-agent-connector
 * Domain Path:       /languages
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/*
 * Another plugin already provides this plugin's classes, functions and data.
 * It loaded first, so this one stays out of the way and says so, instead of a
 * fatal "cannot redeclare" error.
 */
if ( defined( 'AI_AGENT_OS_FILE' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'Another active plugin already provides everything in Zorem AI Agent Connector, so this copy is not running. You can deactivate it; your agent and settings are kept.', 'zorem-ai-agent-connector' ) . '</p></div>';
			}
		}
	);

	return;
}

/** Display name. Not translated here: this file loads before text domains are. */
define( 'AI_AGENT_OS_NAME', 'Zorem AI Agent Connector' );

define( 'AI_AGENT_OS_VERSION', '1.4' );
define( 'AI_AGENT_OS_FILE', __FILE__ );
define( 'AI_AGENT_OS_PATH', plugin_dir_path( __FILE__ ) );
define( 'AI_AGENT_OS_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_AGENT_OS_BASENAME', plugin_basename( __FILE__ ) );

define( 'AI_AGENT_OS_MIN_PHP', '8.0' );
define( 'AI_AGENT_OS_MIN_WP', '6.9' );

/** Version of the MCP Adapter bundled as a fallback. Pinned to WooCommerce's vendored copy. */
define( 'AI_AGENT_OS_BUNDLED_ADAPTER_VERSION', '0.1.0' );

/** Lowest bridge version this plugin's code is written against. */
define( 'AI_AGENT_OS_MIN_BRIDGE', '1.0.0' );

/** Lowest Website Brain Kit version this plugin's code is written against. */
define( 'AI_AGENT_OS_MIN_BRAIN', '1.1.0' );

// Announce the bundled shared libraries; for each, the highest bundled version loads on plugins_loaded 1.
require_once AI_AGENT_OS_PATH . 'lib/zorem-agent-bridge/bridge.php';
require_once AI_AGENT_OS_PATH . 'lib/zorem-agent-brain/brain.php';

require_once AI_AGENT_OS_PATH . 'includes/Core/Autoloader.php';
require_once AI_AGENT_OS_PATH . 'includes/functions.php';

\AiAgentOs\Core\Autoloader::register();

/*
 * WooCommerce High-Performance Order Storage (HPOS): compatible. Orders are
 * only read and written through WooCommerce's own APIs (abilities, REST,
 * wc_get_order(), the order object), never as posts, so they work with either
 * order storage. Declared on load, before WooCommerce asks; nothing happens
 * without WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin.
 *
 * Requirements come first. When they are not met — including when the legacy
 * earlier all-in-one plugin is active — this plugin stays inert and says why in an
 * admin notice. It never fatals.
 *
 * A closure, not a named function: PHP declares a top-level function when the
 * file is compiled, before the early return at the top can run, so a named one
 * would clash with another plugin providing these classes the moment both files
 * are loaded.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		$requirements = new \AiAgentOs\Core\Requirements();

		if ( ! $requirements->are_met() ) {
			( new \AiAgentOs\Admin\Notices( $requirements ) )->register();

			return;
		}

		\AiAgentOs\Plugin::instance( $requirements )->boot();
	},
	5
);

register_activation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		\AiAgentOs\Core\Activator::activate( $network_wide );
	}
);

register_deactivation_hook(
	__FILE__,
	static function ( bool $network_wide = false ): void {
		\AiAgentOs\Core\Deactivator::deactivate( $network_wide );
	}
);

// Sites added to, or removed from, a network while the plugin is network-active.
\AiAgentOs\Core\Activator::register_network_hooks();
