<?php
/**
 * Brain Kit facade.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1;

use ZoremAgentBrain\V1\Abilities\Registrar;
use ZoremAgentBrain\V1\Ai\ModelGateway;
use ZoremAgentBrain\V1\Curated\CuratedSync;
use ZoremAgentBrain\V1\Extraction\AbilityOriginRecorder;
use ZoremAgentBrain\V1\Extraction\PluginLocator;
use ZoremAgentBrain\V1\Gate\KnowledgeGate;
use ZoremAgentBrain\V1\Host\HostRegistry;
use ZoremAgentBrain\V1\Host\Uninstaller;
use ZoremAgentBrain\V1\Ingestion\IngestionScheduler;
use ZoremAgentBrain\V1\Schema\Installer;
use ZoremAgentBrain\V1\Scope\Reader;
use ZoremAgentBrain\V1\Scope\ReaderRegistry;
use ZoremAgentBrain\V1\Scope\ReaderScopeResolver;
use ZoremAgentBrain\V1\Scope\ScopeRepository;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Sources\WpContentSource;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;
use ZoremAgentBrain\V1\Store\Precedence;
use ZoremAgentBridge\V1\Bridge;
use ZoremAgentBridge\V1\Contracts\ModelAccessInterface;
use ZoremAgentBridge\V1\Events\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The one entry point a host plugin uses to reach the Website Brain.
 *
 * A host calls three things in its lifecycle:
 *
 * - `activate_host()` from its activation hook: creates the shared store if
 *   this is the first host, upgrades it if this kit is newer, and records the
 *   host.
 * - `ensure_host()` on every admin request: refreshes the host's recorded
 *   version, and records it if it was activated some way that skipped the hook.
 * - `uninstall_host()` from its uninstall.php: leaves, and removes the Brain
 *   only if it was the last host and deletion was chosen (D-11).
 *
 * Every service is built once per request and shared by both hosts, so both
 * read through the same gate, the same caches and the same reader registry.
 * Nothing that serves a reader may use repository() directly: gate() is the
 * door, and it applies scope and records denials.
 */
final class Brain {

	/**
	 * Major version of the kit's public API in this namespace.
	 */
	public const API_MAJOR = 1;

	/**
	 * The capability that manages the Website Brain — the one both Agent OS
	 * plugins grant to administrators.
	 */
	public const CAPABILITY = 'manage_agent_os';

	/**
	 * The name the kit claims bridge contracts under.
	 */
	public const PROVIDER = 'zorem-agent-brain';

	/**
	 * Host registry.
	 *
	 * @var HostRegistry|null
	 */
	private static ?HostRegistry $hosts = null;

	/**
	 * Services, built on first use.
	 *
	 * @var array<string, object>
	 */
	private static array $services = array();

	/**
	 * Whether start() has run this request.
	 *
	 * @var bool
	 */
	private static bool $started = false;

	/**
	 * Whether the pipeline's hooks are registered this request.
	 *
	 * @var bool
	 */
	private static bool $pipeline = false;

	/**
	 * Register the kit's always-on hooks. Called once, by the winning copy's autoloader.
	 *
	 * Only the schema upgrade. Everything that reads or writes knowledge waits
	 * for start(), which a host calls once its own requirements pass — so when
	 * neither host boots (the legacy plugin is active, say), the kit registers
	 * no abilities, schedules nothing and hooks no content events.
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Start the Brain for this request. Called by each booting host; runs once.
	 *
	 * Registers the retrieval abilities and the ability-origin recorder, and —
	 * when the shared store exists — the pipeline: the extraction tick, the
	 * WordPress content and WooCommerce sources, and the curated registry.
	 * Must run before abilities register (plugins_loaded is early enough): the
	 * recorder can only see an ability's origin while it is being registered.
	 */
	public static function start(): void {
		if ( self::$started ) {
			return;
		}

		self::$started = true;

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		self::get( AbilityOriginRecorder::class )->register();

		// The admin REST API, registered once whichever host loaded the kit.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// The kit provides model access to both plugins (plan §8.2).
		add_action( 'zorem_agent_bridge_register', array( self::class, 'register_bridge_providers' ) );

		self::start_pipeline();
	}

	/**
	 * Register the pipeline's hooks, once, if the shared store exists.
	 *
	 * Separate from start() because the store can appear mid-request: a host's
	 * activation creates it after start() ran, and ensure_host() then calls
	 * this so the first request already keeps content in sync.
	 *
	 * @return bool Whether the hooks were registered by this call.
	 */
	public static function start_pipeline(): bool {
		if ( self::$pipeline || ! self::$started || ! Installer::is_installed() ) {
			return false;
		}

		self::$pipeline = true;

		self::get( IngestionScheduler::class )->register();
		self::get( WpContentSource::class )->register();
		self::get( WooCommerceSource::class )->register();
		self::get( CuratedSync::class )->register();

		return true;
	}

	/**
	 * Whether the pipeline's hooks are registered this request.
	 */
	public static function pipeline_started(): bool {
		return self::$pipeline;
	}

	/**
	 * Register `zorem-agent-brain/v1`.
	 */
	public static function register_rest_routes(): void {
		foreach ( array( new Rest\KnowledgeController(), new Rest\SourcesController(), new Rest\ReviewController() ) as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Where the Brain screens appear. Hosts call `Brain::screens()->mount()`.
	 */
	public static function screens(): Admin\ScreenMount {
		return self::get( Admin\ScreenMount::class );
	}

	/**
	 * Claim the bridge contracts the kit provides.
	 *
	 * @param \ZoremAgentBridge\V1\Registry\ProviderRegistry $registry The bridge's provider registry.
	 */
	public static function register_bridge_providers( $registry ): void {
		$registry->claim( ModelAccessInterface::class, self::get( ModelGateway::class ), self::PROVIDER );
	}

	/**
	 * Whether start() has run this request.
	 */
	public static function is_started(): bool {
		return self::$started;
	}

	/**
	 * A host is being deactivated: stop the extraction tick if no other host is active.
	 *
	 * The tick is the kit's, shared by both hosts, so deactivating one must
	 * not stop it while the other still runs. The deactivating plugin is still
	 * listed as active while its hook runs, so it is excluded by basename.
	 *
	 * @param string $basename The deactivating host's plugin basename.
	 * @return bool Whether the tick was unscheduled.
	 */
	public static function deactivate_host( string $basename ): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( HostRegistry::KNOWN_HOSTS as $other ) {
			if ( $other !== $basename && is_plugin_active( $other ) ) {
				return false;
			}
		}

		IngestionScheduler::unschedule();

		return true;
	}

	/**
	 * Register the `agent-brain` ability category.
	 */
	public static function register_ability_category(): void {
		( new Registrar() )->register_category();
	}

	/**
	 * Register the `agent-brain/*` abilities.
	 */
	public static function register_abilities(): void {
		( new Registrar() )->register_abilities();
	}

	/**
	 * Knowledge storage. Not for serving readers — use gate().
	 */
	public static function repository(): KnowledgeRepository {
		return self::get( KnowledgeRepository::class );
	}

	/**
	 * Explicit per-reader rules.
	 */
	public static function scopes(): ScopeRepository {
		return self::get( ScopeRepository::class );
	}

	/**
	 * Scope resolution.
	 */
	public static function resolver(): ReaderScopeResolver {
		return self::get( ReaderScopeResolver::class );
	}

	/**
	 * The one door to knowledge for readers.
	 */
	public static function gate(): KnowledgeGate {
		return self::get( KnowledgeGate::class );
	}

	/**
	 * Which host resolves which reader namespace.
	 */
	public static function readers(): ReaderRegistry {
		return self::get( ReaderRegistry::class );
	}

	/**
	 * Source precedence across entries describing the same plugin.
	 */
	public static function precedence(): Precedence {
		return self::get( Precedence::class );
	}

	/**
	 * The reader for the agent acting in this request, or null.
	 *
	 * Null when no agent is acting, or when no host resolves its namespace —
	 * both mean nothing is readable.
	 */
	public static function current_reader(): ?Reader {
		$agent = Bridge::context()->current();

		return null === $agent ? null : self::readers()->resolve( $agent );
	}

	/**
	 * The reader for this request: the acting agent's, or a signed-in administrator's.
	 *
	 * An agent always decides: when one is acting, its reader is the answer,
	 * and an agent no host resolves reads nothing — never the administrator
	 * fallback. Only with no agent at all does a signed-in administrator read,
	 * as happens when another plugin's MCP connection runs a Brain tool as the
	 * WordPress user it signed in. An administrator can already see every
	 * entry on the Website Brain screens, so this shows nothing new.
	 */
	public static function request_reader(): ?Reader {
		if ( null !== Bridge::context()->current() ) {
			return self::current_reader();
		}

		return self::administrator_reader();
	}

	/**
	 * Whether a Brain tool may be called at all in this request.
	 *
	 * True for an agent that resolves to a reader, and for anyone signed in
	 * when no agent is acting: the tool then answers a signed-in user who may
	 * not read with refusal(), a message they can act on, rather than the
	 * generic refusal WordPress shows for a failed permission check.
	 */
	public static function may_call_tools(): bool {
		if ( null !== Bridge::context()->current() ) {
			return null !== self::current_reader();
		}

		return is_user_logged_in();
	}

	/**
	 * The refusal a Brain tool returns when nobody in this request may read.
	 */
	public static function refusal(): \WP_Error {
		return new \WP_Error(
			'zorem_agent_brain_not_allowed',
			__( 'The Website Brain answers only an AI agent connected through AI Agent OS (or another plugin that hosts the Brain), or a signed-in site administrator. This connection is signed in as a WordPress user who is not an administrator.', 'zorem-ai-agent-connector' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * A reader for the signed-in administrator, when no agent is acting.
	 *
	 * Acts for an administrator, so it reads internal sections, and holds a
	 * grant on every plugin with knowledge. Its ref is `user:<id>`, a namespace
	 * no host claims, so no knowledge rule can be aimed at it from a screen.
	 */
	private static function administrator_reader(): ?Reader {
		$user = wp_get_current_user();

		if ( $user->ID < 1 || ! user_can( $user, 'manage_options' ) ) {
			return null;
		}

		$name = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;

		return new Reader( 'user:' . $user->ID, $name, Reader::ACTS_FOR_ADMIN, true, self::repository()->plugin_slugs() );
	}

	/**
	 * Drop every built service. For tests, which need a fresh registry and caches.
	 */
	public static function reset_services(): void {
		/*
		 * The ability-origin record and the screen mounts survive: both are
		 * built once per process (as abilities register, as hosts boot) and
		 * cannot be rebuilt afterwards.
		 */
		$keep = array();

		foreach ( array( AbilityOriginRecorder::class, Admin\ScreenMount::class ) as $id ) {
			if ( isset( self::$services[ $id ] ) ) {
				$keep[ $id ] = self::$services[ $id ];
			}
		}

		self::$services = $keep;
		self::$hosts    = null;
	}

	/**
	 * A shared service by class name, built once per request (see Services).
	 *
	 * @param string $id Class name.
	 * @return object
	 * @throws \InvalidArgumentException When the kit has no such service.
	 */
	public static function get( string $id ) {
		if ( ! isset( self::$services[ $id ] ) ) {
			$factories = Services::factories();

			if ( ! isset( $factories[ $id ] ) ) {
				throw new \InvalidArgumentException( 'Unknown Brain service: ' . esc_html( $id ) );
			}

			self::$services[ $id ] = $factories[ $id ]( array( self::class, 'get' ) );
		}

		return self::$services[ $id ];
	}

	/**
	 * Upgrade the shared schema when this kit is newer, if the store exists.
	 *
	 * Does not create the store: only a host's activation does that, so a kit
	 * loaded on a site where no host was ever activated writes nothing.
	 */
	public static function maybe_upgrade(): void {
		if ( Installer::is_installed() ) {
			Installer::maybe_upgrade();
		}
	}

	/**
	 * The loaded kit version.
	 */
	public static function version(): string {
		return defined( 'ZOREM_AGENT_BRAIN_VERSION' ) ? (string) ZOREM_AGENT_BRAIN_VERSION : '';
	}

	/**
	 * Every copy announced this request, directory => version.
	 *
	 * @return array<string, string>
	 */
	public static function copies(): array {
		return function_exists( 'zorem_agent_brain_copies' ) ? zorem_agent_brain_copies() : array();
	}

	/**
	 * Whether the loaded kit satisfies a host's minimum, within this major.
	 *
	 * @param string $minimum Lowest acceptable version.
	 */
	public static function satisfies( string $minimum ): bool {
		$version = self::version();

		if ( '' === $version ) {
			return false;
		}

		return version_compare( $version, $minimum, '>=' ) && version_compare( $version, ( self::API_MAJOR + 1 ) . '.0.0-dev', '<' );
	}

	/**
	 * The host registry.
	 */
	public static function hosts(): HostRegistry {
		if ( null === self::$hosts ) {
			self::$hosts = new HostRegistry();
		}

		return self::$hosts;
	}

	/**
	 * A host is being activated: create or upgrade the store, and record the host.
	 *
	 * @param string $slug     Host plugin slug.
	 * @param string $version  Host plugin version.
	 * @param string $basename Host plugin basename.
	 */
	public static function activate_host( string $slug, string $version, string $basename ): void {
		Installer::install();
		self::hosts()->join( $slug, $version, $basename );
		self::start_pipeline();

		/*
		 * The host's own knowledge. Deactivating the host marked its entries
		 * stale while the kit was running, but `activated_plugin` fires before
		 * the kit boots on the activation request, so nothing else clears the
		 * mark here. Keyed on the plugin folder, which is what entries carry,
		 * not on the host id ($slug), which can differ.
		 */
		if ( Installer::is_installed() ) {
			self::get( KnowledgeRepository::class )->set_plugin_stale(
				self::get( PluginLocator::class )->slug_from_plugin_file( $basename ),
				false
			);
		}
	}

	/**
	 * The store's state for status screens: ok, incomplete (with the missing tables) or absent.
	 *
	 * @return array{state: string, missing: array<int, string>, db_error: string}
	 */
	public static function store_status(): array {
		$failure = Installer::failure();

		if ( null !== $failure ) {
			return array(
				'state'    => 'incomplete',
				'missing'  => (array) ( $failure['missing'] ?? array() ),
				'db_error' => (string) ( $failure['db_error'] ?? '' ),
			);
		}

		return array(
			'state'    => Installer::is_installed() ? 'ok' : 'absent',
			'missing'  => array(),
			'db_error' => '',
		);
	}

	/**
	 * A host is running: keep its record current, and announce a first join.
	 *
	 * @param string $slug     Host plugin slug.
	 * @param string $version  Host plugin version.
	 * @param string $basename Host plugin basename.
	 */
	public static function ensure_host( string $slug, string $version, string $basename ): void {
		if ( ! Installer::is_installed() ) {
			Installer::install();
		}

		self::start_pipeline();

		$recorded = self::hosts()->all()[ $slug ] ?? array();

		// Recorded again when the version or the plugin's file changes (AI Agent OS became ai-agent-os-pro/ai-agent-os-pro.php).
		if ( $version === ( $recorded['version'] ?? null ) && $basename === ( $recorded['basename'] ?? null ) ) {
			return;
		}

		$joined = self::hosts()->join( $slug, $version, $basename );

		if ( $joined && class_exists( Events::class ) ) {
			Bridge::events()->publish( Events::BRAIN_HOST_JOINED, array( 'host' => $slug ) );
		}
	}

	/**
	 * A host is being uninstalled.
	 *
	 * @param string $slug Host plugin slug.
	 * @return string One of the Uninstaller outcome constants.
	 */
	public static function uninstall_host( string $slug ): string {
		return ( new Uninstaller( self::hosts() ) )->uninstall_host( $slug );
	}
}
