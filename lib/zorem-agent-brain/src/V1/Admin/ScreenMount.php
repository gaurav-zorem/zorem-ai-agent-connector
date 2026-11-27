<?php
/**
 * Where the Website Brain screens appear.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Admin;

use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Host\HostRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Decision D-10: one Website Brain editor, wherever the hosts are.
 *
 * Each booting host asks to mount the screens under its own menu. The kit
 * decides, once, on `admin_menu`:
 *
 * - **One host** — the screen appears under that host's menu.
 * - **Both** — it appears once, under AI Agent OS's menu, and Agent OS
 *   Experiences' menu gets a "Website Brain" item that links there. One
 *   editor, never two copies of the same settings drifting apart.
 *
 * The kit is a library: it ships no design system of its own. A host passes
 * the location of its bundled ZUI copy, and the kit loads it on its page.
 */
final class ScreenMount {

	public const PAGE_SLUG = 'zorem-agent-brain';

	/** ZUI components the screens use that zui.css does not import yet. */
	public const EXTRA_COMPONENTS = array( 'badge', 'filter-bar', 'note', 'table', 'pagination', 'actions-menu' );

	/**
	 * Mount requests by host slug.
	 *
	 * @var array<string, array{parent: string, zui_dir: string, zui_url: string}>
	 */
	private array $mounts = array();

	/**
	 * The page's hook suffix, once added.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Whether the admin hooks are registered.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Where a host shows the AI provider settings on its own screen, or ''.
	 *
	 * @var string
	 */
	private string $ai_host_url = '';

	/**
	 * Ask to show the Website Brain screens under a host's menu.
	 *
	 * @param string $host        Host slug, one of HostRegistry::KNOWN_HOSTS.
	 * @param string $parent_slug The host's top-level menu slug.
	 * @param string $zui_dir     Absolute path of the host's bundled ZUI copy (with trailing slash).
	 * @param string $zui_url     URL of the same copy (with trailing slash).
	 */
	public function mount( string $host, string $parent_slug, string $zui_dir, string $zui_url ): void {
		if ( ! isset( HostRegistry::KNOWN_HOSTS[ $host ] ) || '' === $parent_slug ) {
			return;
		}

		$this->mounts[ $host ] = array(
			'parent'  => $parent_slug,
			'zui_dir' => trailingslashit( $zui_dir ),
			'zui_url' => trailingslashit( $zui_url ),
		);

		if ( ! $this->registered ) {
			$this->registered = true;

			// After the hosts' own menus (priority 10), so their parents exist.
			add_action( 'admin_menu', array( $this, 'add_pages' ), 20 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		}
	}

	/**
	 * Say that a host shows the AI provider settings on its own screen.
	 *
	 * The key pays for that host's assistants, so its own settings are where a
	 * merchant looks for it. The Website Brain's AI provider tab is then hidden
	 * and every link to it goes to the host's screen. Only a mounted host may ask.
	 *
	 * @param string $host Host slug, already mounted.
	 * @param string $url  The host screen that shows the settings.
	 */
	public function host_ai_settings( string $host, string $url ): void {
		if ( isset( $this->mounts[ $host ] ) && '' !== $url ) {
			$this->ai_host_url = $url;
		}
	}

	/**
	 * The host screen that shows the AI provider settings, or '' when the Website Brain does.
	 */
	public function ai_host_url(): string {
		return $this->ai_host_url;
	}

	/**
	 * Where the AI provider settings are: a host's screen, or the Website Brain's tab.
	 */
	public static function ai_url(): string {
		$hosted = Brain::screens()->ai_host_url();

		return '' !== $hosted ? $hosted : self::url( 'ai' );
	}

	/**
	 * The host whose menu holds the screen: AI Agent OS when mounted, else the only one.
	 */
	public function owner(): string {
		foreach ( array_keys( HostRegistry::KNOWN_HOSTS ) as $host ) {
			if ( isset( $this->mounts[ $host ] ) ) {
				return $host;
			}
		}

		return '';
	}

	/**
	 * Every mount request.
	 *
	 * @return array<string, array{parent: string, zui_dir: string, zui_url: string}>
	 */
	public function mounts(): array {
		return $this->mounts;
	}

	/**
	 * The screen's URL, optionally on a tab.
	 *
	 * @param string $tab Tab slug.
	 */
	public static function url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' === $tab ? $url : add_query_arg( 'tab', $tab, $url );
	}

	/**
	 * Add the page under the owner, and a link under every other host.
	 */
	public function add_pages(): void {
		$owner = $this->owner();

		if ( '' === $owner ) {
			return;
		}

		$label = __( 'Website Brain', 'zorem-ai-agent-connector' );

		$this->hook_suffix = (string) add_submenu_page(
			$this->mounts[ $owner ]['parent'],
			$label,
			$label,
			Brain::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);

		global $admin_page_hooks;

		foreach ( $this->mounts as $host => $mount ) {
			/*
			 * A link only under a host with its own top-level menu, and not one
			 * that shares the owner's menu (which already holds the one
			 * Website Brain item).
			 */
			if ( $host === $owner || ! isset( $admin_page_hooks[ $mount['parent'] ] ) || $mount['parent'] === $this->mounts[ $owner ]['parent'] ) {
				continue;
			}

			// A slug with ".php" in it is a link: this item opens the one editor.
			add_submenu_page( $mount['parent'], $label, $label, Brain::CAPABILITY, 'admin.php?page=' . self::PAGE_SLUG );
		}
	}

	/**
	 * Load the owner's ZUI and the kit's own script on the page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		self::enqueue_assets( $this->mounts[ $this->owner() ]['zui_url'] );
	}

	/**
	 * Load ZUI and the kit's own style and script, for the Brain's page or a host's.
	 *
	 * A host that shows a Brain panel on its own screen (the AI provider
	 * settings) calls this on that screen with its ZUI copy.
	 *
	 * @param string $zui_url URL of the ZUI copy (with trailing slash).
	 */
	public static function enqueue_assets( string $zui_url ): void {
		$zui_url = trailingslashit( $zui_url );
		$version = Brain::version();

		wp_enqueue_style( 'zui', $zui_url . 'css/zui.css', array(), $version );
		wp_enqueue_script( 'zui', $zui_url . 'js/zui.js', array(), $version, true );

		/*
		 * ZUI's "phase 2" components ship on disk but zui.css does not import
		 * them yet; the Brain screens use these, so they load individually from
		 * the same copy. The vendored library itself is never edited.
		 */
		$deps = array( 'zui' );

		foreach ( self::EXTRA_COMPONENTS as $component ) {
			wp_enqueue_style( 'zui-' . $component, $zui_url . 'css/components/' . $component . '.css', array( 'zui' ), $version );
			$deps[] = 'zui-' . $component;
		}

		$kit = self::kit_url();

		/*
		 * The kit's own files change between kit versions too (a style fix ships
		 * inside a plugin update without a new kit number), so their file time is
		 * part of the cache key: a browser never keeps an old copy after an update.
		 */
		$dir = ( defined( 'ZOREM_AGENT_BRAIN_DIR' ) ? trailingslashit( (string) ZOREM_AGENT_BRAIN_DIR ) : trailingslashit( dirname( __DIR__, 3 ) ) ) . 'assets/';

		wp_enqueue_style( 'zorem-agent-brain-admin', $kit . 'assets/admin.css', $deps, $version . '.' . (int) @filemtime( $dir . 'admin.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the kit version.
		wp_enqueue_script( 'zorem-agent-brain-admin', $kit . 'assets/admin.js', array( 'jquery', 'zui' ), $version . '.' . (int) @filemtime( $dir . 'admin.js' ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A missing file falls back to the kit version.
		wp_localize_script(
			'zorem-agent-brain-admin',
			'zoremAgentBrain',
			array(
				'root'  => esc_url_raw( rest_url( \ZoremAgentBrain\V1\Rest\Controller::NAMESPACE . '/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'saved'     => __( 'Saved.', 'zorem-ai-agent-connector' ),
					'ackFirst'  => __( 'Tick the acknowledgement above first, then save the key.', 'zorem-ai-agent-connector' ),
					'failed'    => __( 'That did not work.', 'zorem-ai-agent-connector' ),
					'working'   => __( 'Working…', 'zorem-ai-agent-connector' ),
					'confirm'   => __( 'Are you sure?', 'zorem-ai-agent-connector' ),
					'rejected'  => __( 'Not imported:', 'zorem-ai-agent-connector' ),
					'noResults' => __( 'Nothing found.', 'zorem-ai-agent-connector' ),
				),
			)
		);
	}

	/**
	 * Render the screen for the owner.
	 */
	public function render(): void {
		if ( ! current_user_can( Brain::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Website Brain.', 'zorem-ai-agent-connector' ), 403 );
		}

		$mount = $this->mounts[ $this->owner() ] ?? null;

		if ( null !== $mount && is_readable( $mount['zui_dir'] . 'icons.php' ) ) {
			require_once $mount['zui_dir'] . 'icons.php';
		}

		( new Screen() )->render( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab selection.
	}

	/**
	 * The URL of the loaded kit copy (inside whichever host bundled it).
	 */
	private static function kit_url(): string {
		$dir = defined( 'ZOREM_AGENT_BRAIN_DIR' ) ? (string) ZOREM_AGENT_BRAIN_DIR : trailingslashit( dirname( __DIR__, 3 ) );

		return trailingslashit( plugins_url( '', $dir . 'brain.php' ) );
	}
}
