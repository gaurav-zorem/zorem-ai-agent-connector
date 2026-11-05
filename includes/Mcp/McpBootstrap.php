<?php
/**
 * MCP adapter bootstrap.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Core\Requirements;
use AiAgentOs\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Starts the MCP adapter and hooks our server construction into it.
 *
 * Every reference to the WP\MCP namespace in this plugin lives in this
 * directory. The adapter is pre-1.0 and will change; keeping the coupling in
 * one place means an adapter break is a local repair rather than a rewrite.
 */
final class McpBootstrap {

	/**
	 * Environment detection.
	 *
	 * @var Requirements
	 */
	private Requirements $requirements;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Server construction.
	 *
	 * @var ServerBuilder
	 */
	private ServerBuilder $builder;

	/**
	 * Constructor.
	 *
	 * @param Requirements  $requirements Environment detection.
	 * @param Settings      $settings     Plugin settings.
	 * @param ServerBuilder $builder      Server construction.
	 */
	public function __construct( Requirements $requirements, Settings $settings, ServerBuilder $builder ) {
		$this->requirements = $requirements;
		$this->settings     = $settings;
		$this->builder      = $builder;
	}

	/**
	 * Wire the adapter.
	 */
	public function register(): void {
		/*
		 * The adapter's default server exposes mcp-adapter/execute-ability,
		 * which runs any ability by name — an obvious way around a per-tool
		 * allow-list. AbilityGate denies agent-context calls through it
		 * regardless, but a closed door beats a guarded one, so it is never
		 * created, and there is no setting to open it.
		 *
		 * This filter has to be added before McpAdapter::init() runs, since
		 * maybe_create_default_server() consults it there.
		 */
		add_filter( 'mcp_adapter_create_default_server', '__return_false', 20 );

		if ( ! $this->settings->bool( 'mcp_enabled' ) || ! $this->requirements->has_mcp_adapter() ) {
			return;
		}

		add_action( 'mcp_adapter_init', array( $this, 'on_adapter_init' ) );

		$this->boot_adapter();
	}

	/**
	 * Make sure the adapter has registered its own hooks.
	 *
	 * WooCommerce only calls this when its mcp_integration feature flag is on,
	 * and on a site without WooCommerce nothing calls it at all. It is
	 * idempotent, so calling it ourselves is safe and makes the endpoint work
	 * on plain WordPress.
	 */
	private function boot_adapter(): void {
		$adapter_class = Requirements::ADAPTER_CLASS;

		if ( ! class_exists( $adapter_class ) || ! method_exists( $adapter_class, 'instance' ) ) {
			return;
		}

		$adapter_class::instance();
	}

	/**
	 * Build our server when the adapter is ready.
	 *
	 * @param object $adapter The McpAdapter instance.
	 */
	public function on_adapter_init( $adapter ): void {
		/*
		 * Force the current user to be resolved before building.
		 *
		 * WordPress determines the current user lazily, on first use. Our
		 * bearer-token resolver runs on determine_current_user, so unless
		 * something has already asked who the user is, the agent would not yet
		 * be in context and we would build an empty server for a perfectly
		 * valid token. Asking here guarantees the resolver has run.
		 */
		wp_get_current_user();

		$this->builder->build( $adapter );
	}
}
