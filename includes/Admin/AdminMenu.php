<?php
/**
 * Admin menu and screen assets.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Admin;

use AiAgentOs\Core\Capabilities;
use AiAgentOs\Core\Requirements;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * The top-level menu and its screen: the agent, settings and status.
 *
 * The slug is a public constant so a plugin that extends this one can attach
 * its own screens beneath this menu instead of adding a second top-level one.
 */
final class AdminMenu {

	/**
	 * Menu slug, also the screen's `page` query value.
	 */
	public const MENU_SLUG = 'ai-agent-os';

	/**
	 * Requirements, shown on the status screen.
	 *
	 * @var Requirements
	 */
	private Requirements $requirements;

	/**
	 * Hook suffix of the screen, set when the menu registers.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Requirements $requirements Checked requirements.
	 */
	public function __construct( Requirements $requirements ) {
		$this->requirements = $requirements;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the top-level menu.
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			AI_AGENT_OS_NAME,
			// Shorter than the plugin's own name on purpose: the admin menu column is
			// narrow, and the full name wraps onto three lines there. The page itself
			// still says who it is.
			__( 'AI Agent Connector', 'zorem-ai-agent-connector' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-shield',
			58
		);
	}

	/**
	 * Load the screen's styles, only on this screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$zui_url     = AI_AGENT_OS_URL . 'assets/zui/';
		$zui_version = $this->zui_version();

		wp_enqueue_style( 'zui', $zui_url . 'css/zui.css', array(), $zui_version );

		/*
		 * ZUI components that zui.css keeps out of its imports until a consumer
		 * ships: these screens render tables, badges, a textarea, filter bars,
		 * notes, row action menus and the Status tiles. Loaded from
		 * the same vendored copy, unmodified. Plus the lock card, PRO pill and
		 * upsell panel of the locked AI Agent OS PRO previews.
		 */
		foreach ( array( 'table', 'badge', 'textarea', 'filter-bar', 'note', 'actions-menu', 'stat-card', 'lock-section', 'pro-feature', 'upsell' ) as $component ) {
			wp_enqueue_style( 'zui-' . $component, $zui_url . 'css/components/' . $component . '.css', array( 'zui' ), $zui_version );
		}

		wp_enqueue_script( 'zui', $zui_url . 'js/zui.js', array(), $zui_version, true );

		wp_enqueue_style(
			'ai-agent-os-admin',
			AI_AGENT_OS_URL . 'assets/css/admin.css',
			array( 'zui' ),
			$this->asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'ai-agent-os-admin',
			AI_AGENT_OS_URL . 'assets/js/admin.js',
			array( 'zui', 'jquery' ),
			$this->asset_version( 'assets/js/admin.js' ),
			true
		);

		foreach ( array( 'agent' ) as $screen ) {
			wp_enqueue_script(
				'ai-agent-os-' . $screen,
				AI_AGENT_OS_URL . 'assets/js/' . $screen . '.js',
				array( 'ai-agent-os-admin' ),
				$this->asset_version( 'assets/js/' . $screen . '.js' ),
				true
			);
		}

		wp_localize_script( 'ai-agent-os-admin', 'aiAgentOs', $this->client_config() );
	}

	/**
	 * What the screens' scripts need: where the API is, and every string they show.
	 *
	 * @return array<string, mixed>
	 */
	private function client_config(): array {
		return array(
			'root'        => esc_url_raw( rest_url( Routes::ADMIN_NAMESPACE . '/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),

			// The agent's own screen, for a redirect after an action that changes it.
			'agentUrl'    => esc_url_raw( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&tab=agent' ) ),
			'pluginName'  => AI_AGENT_OS_NAME,
			'version'     => AI_AGENT_OS_VERSION,
			'hasWoo'      => class_exists( 'WooCommerce' ),
			'hasAdapter'  => $this->requirements->has_mcp_adapter(),
			'saltRotated' => $this->requirements->salt_has_rotated(),
			'i18n'        => array(
				'genericError'     => __( 'Something went wrong. Please try again.', 'zorem-ai-agent-connector' ),
				'saving'           => __( 'Saving…', 'zorem-ai-agent-connector' ),
				'saved'            => __( 'Saved', 'zorem-ai-agent-connector' ),
				'changesSaved'     => __( 'Your changes have been saved.', 'zorem-ai-agent-connector' ),
				'copied'           => __( 'Copied', 'zorem-ai-agent-connector' ),

				/*
				 * Separate strings rather than one with %d: `_n()` cannot run in
				 * JavaScript, so the script picks from these.
				 */
				/* translators: %d: number of abilities the agent may now use. */
				'permissionsSaved' => __( 'Saved. This agent may now use %d abilities.', 'zorem-ai-agent-connector' ),
				'permissionSaved'  => __( 'Saved. This agent may now use 1 ability.', 'zorem-ai-agent-connector' ),
				'permissionsNone'  => __( 'Saved. This agent may not use any abilities.', 'zorem-ai-agent-connector' ),
				/* translators: 1: granted count, 2: total abilities in the section. */
				'grantedOf'        => __( '%1$d of %2$d granted', 'zorem-ai-agent-connector' ),
				/* translators: %s: number of granted abilities. */
				'toolGranted'      => __( '%s tool granted', 'zorem-ai-agent-connector' ),
				/* translators: %s: number of granted abilities. */
				'toolsGranted'     => __( '%s tools granted', 'zorem-ai-agent-connector' ),
				'unsavedChanges'   => __( 'You have unsaved changes on this screen. Leave without saving?', 'zorem-ai-agent-connector' ),
				/* translators: 1: the value that was entered, 2: the value that was stored. */
				'adjusted'         => __( '%1$s is outside the allowed range — saved as %2$s.', 'zorem-ai-agent-connector' ),
				'allRevoked'       => __( 'Every permission revoked.', 'zorem-ai-agent-connector' ),
				'details'          => __( 'Details', 'zorem-ai-agent-connector' ),
				'hide'             => __( 'Hide', 'zorem-ai-agent-connector' ),
			),
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this plugin.', 'zorem-ai-agent-connector' ), 403 );
		}

		$ai_agent_os_requirements = $this->requirements;

		require AI_AGENT_OS_PATH . 'includes/Admin/Screen/layout-app.php';
	}

	/**
	 * The vendored ZUI library's version, for cache busting.
	 */
	private function zui_version(): string {
		$file = AI_AGENT_OS_PATH . 'assets/zui/VERSION';

		return is_readable( $file ) ? trim( (string) file_get_contents( $file ) ) : AI_AGENT_OS_VERSION; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file inside the plugin.
	}

	/**
	 * Version string for a plugin asset: plugin version plus file time.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	private function asset_version( string $relative ): string {
		$time = (int) @filemtime( AI_AGENT_OS_PATH . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the plugin version.

		return AI_AGENT_OS_VERSION . ( $time > 0 ? '.' . $time : '' );
	}
}
