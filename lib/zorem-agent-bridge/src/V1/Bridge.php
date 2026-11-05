<?php
/**
 * Bridge facade.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1;

use ZoremAgentBridge\V1\Context\LocalAgentContext;
use ZoremAgentBridge\V1\Contracts\AgentContextProviderInterface;
use ZoremAgentBridge\V1\Contracts\AgentDirectoryInterface;
use ZoremAgentBridge\V1\Contracts\AuditSinkInterface;
use ZoremAgentBridge\V1\Contracts\HandoffRouterInterface;
use ZoremAgentBridge\V1\Contracts\ModelAccessInterface;
use ZoremAgentBridge\V1\Contracts\PermissionAuthorityInterface;
use ZoremAgentBridge\V1\Contracts\SkillCatalogInterface;
use ZoremAgentBridge\V1\Diagnostics\Diagnostics;
use ZoremAgentBridge\V1\Events\EventBus;
use ZoremAgentBridge\V1\Events\Events;
use ZoremAgentBridge\V1\Fallback\NullAgentDirectory;
use ZoremAgentBridge\V1\Fallback\NullAuditSink;
use ZoremAgentBridge\V1\Fallback\NullHandoffRouter;
use ZoremAgentBridge\V1\Fallback\NullModelAccess;
use ZoremAgentBridge\V1\Fallback\NullPermissionAuthority;
use ZoremAgentBridge\V1\Fallback\NullSkillCatalog;
use ZoremAgentBridge\V1\Registry\FeatureRegistry;
use ZoremAgentBridge\V1\Registry\ProviderRegistry;
use ZoremAgentBridge\V1\Vocabulary\ReasonVocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * The one entry point a plugin uses to reach the bridge.
 *
 * Registration happens once per request, on `plugins_loaded` priority 20:
 * the bridge fires `zorem_agent_bridge_register` with the provider registry,
 * the feature registry and the reason vocabulary, then seals both registries.
 * After that, the provider getters return the claimed provider — or, when a
 * contract is unclaimed or conflicted, its safe fallback. A caller never gets
 * null and never has to know whether the other plugin is installed.
 */
final class Bridge {

	/**
	 * Major version of the contracts in this namespace.
	 *
	 * A breaking change starts `ZoremAgentBridge\V2`, which can load beside V1.
	 */
	public const CONTRACT_MAJOR = 1;

	/**
	 * Contracts only AI Agent OS may provide. Listed for diagnostics and docs;
	 * the claim-once rule already applies to every contract.
	 *
	 * @var array<int, string>
	 */
	public const AUTHORITY_CONTRACTS = array(
		AgentDirectoryInterface::class,
		AgentContextProviderInterface::class,
		PermissionAuthorityInterface::class,
	);

	/**
	 * Provider registry.
	 *
	 * @var ProviderRegistry|null
	 */
	private static ?ProviderRegistry $registry = null;

	/**
	 * Feature registry.
	 *
	 * @var FeatureRegistry|null
	 */
	private static ?FeatureRegistry $features = null;

	/**
	 * Event bus.
	 *
	 * @var EventBus|null
	 */
	private static ?EventBus $events = null;

	/**
	 * Reason vocabulary.
	 *
	 * @var ReasonVocabulary|null
	 */
	private static ?ReasonVocabulary $vocabulary = null;

	/**
	 * Context used when no provider claims the context contract.
	 *
	 * @var LocalAgentContext|null
	 */
	private static ?LocalAgentContext $local_context = null;

	/**
	 * Register the bridge's own hooks. Called once, by the winning copy's autoloader.
	 */
	public static function boot(): void {
		// Priority 20: after every plugin has booted at 5 or 10, so all of them can register.
		add_action( 'plugins_loaded', array( self::class, 'open_registration' ), 20 );
		add_action( 'admin_notices', array( Diagnostics::class, 'render_conflict_notice' ) );
		add_action( 'network_admin_notices', array( Diagnostics::class, 'render_conflict_notice' ) );
	}

	/**
	 * Fire the registration action, then seal the registries.
	 */
	public static function open_registration(): void {
		if ( self::registry()->is_sealed() ) {
			return;
		}

		/**
		 * Register bridge providers, features and reason labels.
		 *
		 * The only moment claims are accepted. Anything claimed later is
		 * refused and shown in diagnostics.
		 *
		 * @param ProviderRegistry $registry   Claim contracts here.
		 * @param FeatureRegistry  $features   Announce features here.
		 * @param ReasonVocabulary $vocabulary Name reason slugs here.
		 */
		do_action( 'zorem_agent_bridge_register', self::registry(), self::features(), self::vocabulary() );

		self::registry()->seal();
		self::features()->seal();

		foreach ( self::registry()->conflicts() as $contract => $owners ) {
			self::events()->publish(
				Events::BRIDGE_CONFLICT,
				array(
					'contract' => $contract,
					'owners'   => $owners,
				)
			);
		}
	}

	/**
	 * The loaded bridge version.
	 */
	public static function version(): string {
		return defined( 'ZOREM_AGENT_BRIDGE_VERSION' ) ? (string) ZOREM_AGENT_BRIDGE_VERSION : '';
	}

	/**
	 * Every copy announced this request, directory => version.
	 *
	 * @return array<string, string>
	 */
	public static function copies(): array {
		return function_exists( 'zorem_agent_bridge_copies' ) ? zorem_agent_bridge_copies() : array();
	}

	/**
	 * Whether the loaded bridge satisfies a consumer's minimum, within this major.
	 *
	 * @param string $minimum Lowest acceptable version, e.g. "1.0.0-dev".
	 */
	public static function satisfies( string $minimum ): bool {
		$version = self::version();

		if ( '' === $version ) {
			return false;
		}

		$next_major = ( self::CONTRACT_MAJOR + 1 ) . '.0.0-dev';

		return version_compare( $version, $minimum, '>=' ) && version_compare( $version, $next_major, '<' );
	}

	/**
	 * The provider registry.
	 */
	public static function registry(): ProviderRegistry {
		if ( null === self::$registry ) {
			self::$registry = new ProviderRegistry();
		}

		return self::$registry;
	}

	/**
	 * The feature registry.
	 */
	public static function features(): FeatureRegistry {
		if ( null === self::$features ) {
			self::$features = new FeatureRegistry();
		}

		return self::$features;
	}

	/**
	 * The event bus.
	 */
	public static function events(): EventBus {
		if ( null === self::$events ) {
			self::$events = new EventBus();
		}

		return self::$events;
	}

	/**
	 * The reason vocabulary.
	 */
	public static function vocabulary(): ReasonVocabulary {
		if ( null === self::$vocabulary ) {
			self::$vocabulary = new ReasonVocabulary();
		}

		return self::$vocabulary;
	}

	/**
	 * Whether a contract has a provider (claimed once, not in conflict).
	 *
	 * @param string $contract Interface name.
	 */
	public static function has_provider( string $contract ): bool {
		return null !== self::registry()->get( $contract );
	}

	/**
	 * The agent directory, or a fallback that knows no agents.
	 */
	public static function directory(): AgentDirectoryInterface {
		$provider = self::registry()->get( AgentDirectoryInterface::class );

		return $provider instanceof AgentDirectoryInterface ? $provider : new NullAgentDirectory();
	}

	/**
	 * The agent context, or the bridge's own in-request context.
	 */
	public static function context(): AgentContextProviderInterface {
		$provider = self::registry()->get( AgentContextProviderInterface::class );

		if ( $provider instanceof AgentContextProviderInterface ) {
			return $provider;
		}

		if ( null === self::$local_context ) {
			self::$local_context = new LocalAgentContext();
		}

		return self::$local_context;
	}

	/**
	 * The permission authority, or a fallback that denies everything.
	 */
	public static function permissions(): PermissionAuthorityInterface {
		$provider = self::registry()->get( PermissionAuthorityInterface::class );

		return $provider instanceof PermissionAuthorityInterface ? $provider : new NullPermissionAuthority();
	}

	/**
	 * The skill catalog, or a fallback with no manuals.
	 */
	public static function skills(): SkillCatalogInterface {
		$provider = self::registry()->get( SkillCatalogInterface::class );

		return $provider instanceof SkillCatalogInterface ? $provider : new NullSkillCatalog();
	}

	/**
	 * The audit sink, or a fallback that accepts nothing (so the caller logs locally).
	 */
	public static function audit(): AuditSinkInterface {
		$provider = self::registry()->get( AuditSinkInterface::class );

		return $provider instanceof AuditSinkInterface ? $provider : new NullAuditSink();
	}

	/**
	 * The handoff router, or a fallback that refuses.
	 */
	public static function handoffs(): HandoffRouterInterface {
		$provider = self::registry()->get( HandoffRouterInterface::class );

		return $provider instanceof HandoffRouterInterface ? $provider : new NullHandoffRouter();
	}

	/**
	 * Model access, or a fallback that is unavailable.
	 */
	public static function model(): ModelAccessInterface {
		$provider = self::registry()->get( ModelAccessInterface::class );

		return $provider instanceof ModelAccessInterface ? $provider : new NullModelAccess();
	}
}
