<?php
/**
 * Plugin bootstrap.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\CoreAbilities;
use AiAgentOs\Admin\AdminMenu;
use AiAgentOs\Agents\AgentFactory;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Agents\ShadowUserManager;
use AiAgentOs\Agents\TokenService;
use AiAgentOs\Audit\ActivityRecorder;
use AiAgentOs\Audit\CallAnnotations;
use AiAgentOs\Auth\AgentAuthenticator;
use AiAgentOs\Auth\BearerTokenResolver;
use AiAgentOs\Bridge\BridgeRegistration;
use AiAgentOs\Bridge\CoreAgentContext;
use AiAgentOs\Bridge\CoreAgentDirectory;
use AiAgentOs\Bridge\CorePermissionAuthority;
use AiAgentOs\Core\Container;
use AiAgentOs\Core\Cron;
use AiAgentOs\Core\Installer;
use AiAgentOs\Core\Requirements;
use AiAgentOs\Core\Settings;
use AiAgentOs\Governance\AbilityGate;
use AiAgentOs\Governance\GovernedAbilities;
use AiAgentOs\Governance\GrantAuthority;
use AiAgentOs\Governance\AbilityInventory;
use AiAgentOs\Governance\CapabilityBridge;
use AiAgentOs\Governance\CapMaps\CapMapLoader;
use AiAgentOs\Governance\PermissionRepository;
use AiAgentOs\Governance\PermissionResolver;
use AiAgentOs\Governance\RiskClassifier;
use AiAgentOs\Mcp\McpBootstrap;
use AiAgentOs\Mcp\ServerBuilder;
use AiAgentOs\Mcp\TransportPermission;
use AiAgentOs\Privacy\CoveredAbilities;
use AiAgentOs\Privacy\CustomerDataExposure;
use AiAgentOs\Privacy\PiiRedactor;
use AiAgentOs\Rest\AgentsController;
use AiAgentOs\Rest\SettingsController;
use AiAgentOs\Compat\LegacyApi;

/**
 * Wires services into WordPress.
 *
 * `boot()` only registers hooks: nothing does work at construction time and no
 * service touches the database until asked. `register_services()` is the map
 * of the whole system — every service the plugin has is registered there.
 */
final class Plugin {

	/**
	 * The instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 *
	 * @param Requirements $requirements The checked requirements.
	 */
	private function __construct( Requirements $requirements ) {
		$this->container = new Container();
		$this->container->instance( Requirements::class, $requirements );
		$this->register_services();
	}

	/**
	 * The instance, created on first call.
	 *
	 * @param Requirements|null $requirements The checked requirements; used only on first call.
	 */
	public static function instance( ?Requirements $requirements = null ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $requirements ?? new Requirements() );
		}

		return self::$instance;
	}

	/**
	 * The service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register hooks. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );

		/*
		 * Governance. The gate wraps every ability as it registers, so it is
		 * hooked first; the transport, the journal and MCP follow. With no
		 * agent acting, every one of them is transparent.
		 */
		$this->container->get( AbilityGate::class )->register();
		( new \AiAgentOs\Abilities\WhoAmI() )->register();
		$this->container->get( CoreAbilities::class )->register();
		$this->container->get( \AiAgentOs\Agents\SiteAgent::class )->register();
		$this->container->get( ShadowUserManager::class )->register();
		$this->container->get( AgentAuthenticator::class )->register();
		$this->container->get( Mcp\NotFoundResponse::class )->register();
		// The agent's own MCP address, given to it when it is set up.
		$this->container->get( Mcp\AgentAddress::class )->register();
		$this->container->get( McpBootstrap::class )->register();

		// The connect flow: discovery, the OAuth endpoints, and the consent screen (admin).
		$this->container->get( OAuth\OAuthStore::class )->register();
		$this->container->get( OAuth\DiscoveryServer::class )->register();
		$this->container->get( OAuth\OAuthController::class )->register();
		$this->container->get( Cron::class )->register();

		// The legacy plugin's public functions, now that it is known not to be active.
		LegacyApi::load();

		/**
		 * Bridge registration.
		 *
		 * @var BridgeRegistration $bridge
		 */
		$bridge = $this->container->get( BridgeRegistration::class );
		$bridge->register();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		if ( is_admin() ) {
			$this->container->get( OAuth\ConsentScreen::class )->register();

			/**
			 * Admin menu.
			 *
			 * @var AdminMenu $menu
			 */
			$menu = $this->container->get( AdminMenu::class );
			$menu->register();
		}

		/**
		 * Fires once this plugin has registered its hooks.
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'ai_agent_os_loaded', $this );
	}

	/**
	 * Register the administrator REST routes. Every one needs `manage_agent_os` and the REST nonce.
	 */
	public function register_routes(): void {
		$controllers = array( AgentsController::class, SettingsController::class );

		foreach ( $controllers as $controller ) {
			$this->container->get( $controller )->register_routes();
		}
	}

	/**
	 * Every service the plugin provides.
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->set( Settings::class, static fn (): Settings => new Settings() );

		$c->set( BridgeRegistration::class, static fn ( Container $c ): BridgeRegistration => new BridgeRegistration( $c ) );

		// Identity.
		$c->set( AgentRepository::class, static fn (): AgentRepository => new AgentRepository() );
		$c->set( TokenService::class, static fn (): TokenService => new TokenService() );
		$c->set( ShadowUserManager::class, static fn (): ShadowUserManager => new ShadowUserManager() );
		$c->set(
			AgentFactory::class,
			static fn ( Container $c ): AgentFactory => new AgentFactory(
				$c->get( AgentRepository::class ),
				$c->get( ShadowUserManager::class ),
				$c->get( TokenService::class )
			)
		);
		$c->set( BearerTokenResolver::class, static fn ( Container $c ): BearerTokenResolver => new BearerTokenResolver( $c->get( AgentRepository::class ), $c->get( TokenService::class ) ) );
		$c->set( AgentAuthenticator::class, static fn ( Container $c ): AgentAuthenticator => new AgentAuthenticator( $c->get( BearerTokenResolver::class ), $c->get( AgentRepository::class ), $c->get( Auth\OAuthAccessTokenResolver::class ) ) );

		// The connect flow (OAuth for MCP clients).
		$c->set( OAuth\OAuthStore::class, static fn ( Container $c ): OAuth\OAuthStore => new OAuth\OAuthStore( $c->get( TokenService::class ) ) );
		$c->set( OAuth\CimdFetcher::class, static fn (): OAuth\CimdFetcher => new OAuth\CimdFetcher() );
		$c->set( OAuth\OAuthMetadata::class, static fn ( Container $c ): OAuth\OAuthMetadata => new OAuth\OAuthMetadata( $c->get( Settings::class ) ) );
		$c->set( OAuth\ClientResolver::class, static fn ( Container $c ): OAuth\ClientResolver => new OAuth\ClientResolver( $c->get( OAuth\CimdFetcher::class ), $c->get( OAuth\OAuthStore::class ), $c->get( Settings::class ) ) );
		$c->set( OAuth\DiscoveryServer::class, static fn ( Container $c ): OAuth\DiscoveryServer => new OAuth\DiscoveryServer( $c->get( OAuth\OAuthMetadata::class ), $c->get( Settings::class ) ) );
		$c->set(
			OAuth\OAuthController::class,
			static fn ( Container $c ): OAuth\OAuthController => new OAuth\OAuthController(
				$c->get( OAuth\OAuthMetadata::class ),
				$c->get( OAuth\OAuthStore::class ),
				$c->get( OAuth\ClientResolver::class ),
				$c->get( AgentRepository::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			OAuth\ConsentScreen::class,
			static fn ( Container $c ): OAuth\ConsentScreen => new OAuth\ConsentScreen(
				$c->get( OAuth\ClientResolver::class ),
				$c->get( OAuth\OAuthStore::class ),
				$c->get( AgentRepository::class ),
				$c->get( PermissionRepository::class ),
				$c->get( PermissionResolver::class ),
				$c->get( AbilityInventory::class ),
				$c->get( Settings::class )
			)
		);
		$c->set(
			Auth\OAuthAccessTokenResolver::class,
			static fn ( Container $c ): Auth\OAuthAccessTokenResolver => new Auth\OAuthAccessTokenResolver(
				$c->get( OAuth\OAuthStore::class ),
				$c->get( AgentRepository::class ),
				$c->get( BearerTokenResolver::class )
			)
		);

		// Permissions.
		$c->set( CoreAbilities::class, static fn (): CoreAbilities => new CoreAbilities() );
		$c->set( CapMapLoader::class, static fn ( Container $c ): CapMapLoader => new CapMapLoader( $c->get( CoreAbilities::class ) ) );
		$c->set( RiskClassifier::class, static fn (): RiskClassifier => new RiskClassifier() );
		$c->set( GovernedAbilities::class, static fn (): GovernedAbilities => new GovernedAbilities() );
		$c->set( Core\SafetyChecks::class, static fn ( Container $c ): Core\SafetyChecks => new Core\SafetyChecks( $c->get( GovernedAbilities::class ) ) );
		$c->set( GrantAuthority::class, static fn ( Container $c ): GrantAuthority => new GrantAuthority( $c->get( CapMapLoader::class ), $c->get( AbilityInventory::class ) ) );
		$c->set( AbilityInventory::class, static fn ( Container $c ): AbilityInventory => new AbilityInventory( $c->get( RiskClassifier::class ), $c->get( CapMapLoader::class ) ) );
		$c->set( CapabilityBridge::class, static fn ( Container $c ): CapabilityBridge => new CapabilityBridge( $c->get( CapMapLoader::class ) ) );
		$c->set( PermissionRepository::class, static fn (): PermissionRepository => new PermissionRepository() );
		$c->set( \AiAgentOs\Agents\SiteAgent::class, static fn ( Container $c ): \AiAgentOs\Agents\SiteAgent => new \AiAgentOs\Agents\SiteAgent( $c->get( AgentFactory::class ), $c->get( AbilityInventory::class ), $c->get( PermissionRepository::class ) ) );
		$c->set(
			PermissionResolver::class,
			static fn ( Container $c ): PermissionResolver => new PermissionResolver( $c->get( PermissionRepository::class ), $c->get( RiskClassifier::class ), $c->get( AbilityInventory::class ), $c->get( Settings::class ), $c->get( GovernedAbilities::class ) )
		);
		$c->set(
			AbilityGate::class,
			static fn ( Container $c ): AbilityGate => new AbilityGate( $c->get( PermissionResolver::class ), $c->get( CapabilityBridge::class ), $c->get( ActivityRecorder::class ), $c->get( PiiRedactor::class ), $c->get( CallAnnotations::class ), $c->get( RiskClassifier::class ), $c->get( GovernedAbilities::class ) )
		);

		// Activity and privacy.
		$c->set( ActivityRecorder::class, static fn (): ActivityRecorder => new ActivityRecorder() );
		$c->set( CallAnnotations::class, static fn (): CallAnnotations => new CallAnnotations() );
		$c->set( Cron::class, static fn ( Container $c ): Cron => new Cron( $c->get( Settings::class ) ) );
		$c->set( CoveredAbilities::class, static fn (): CoveredAbilities => new CoveredAbilities() );
		$c->set( PiiRedactor::class, static fn ( Container $c ): PiiRedactor => new PiiRedactor( $c->get( CoveredAbilities::class ) ) );
		$c->set( CustomerDataExposure::class, static fn ( Container $c ): CustomerDataExposure => new CustomerDataExposure( $c->get( CoveredAbilities::class ), $c->get( PermissionRepository::class ) ) );

		// MCP.
		$c->set( TransportPermission::class, static fn ( Container $c ): TransportPermission => new TransportPermission( $c->get( ActivityRecorder::class ) ) );
		$c->set( Mcp\AgentAddress::class, static fn ( Container $c ): Mcp\AgentAddress => new Mcp\AgentAddress( $c->get( AgentRepository::class ) ) );
		$c->set( ServerBuilder::class, static fn ( Container $c ): ServerBuilder => new ServerBuilder( $c->get( PermissionResolver::class ), $c->get( TransportPermission::class ), $c->get( Mcp\AgentAddress::class ) ) );
		$c->set( McpBootstrap::class, static fn ( Container $c ): McpBootstrap => new McpBootstrap( $c->get( Requirements::class ), $c->get( Settings::class ), $c->get( ServerBuilder::class ) ) );

		// What Core provides to other plugins through the bridge.
		$c->set( CoreAgentContext::class, static fn ( Container $c ): CoreAgentContext => new CoreAgentContext( $c->get( AgentRepository::class ) ) );
		$c->set( CoreAgentDirectory::class, static fn ( Container $c ): CoreAgentDirectory => new CoreAgentDirectory( $c->get( AgentRepository::class ) ) );
		$c->set( CorePermissionAuthority::class, static fn ( Container $c ): CorePermissionAuthority => new CorePermissionAuthority( $c->get( AgentRepository::class ), $c->get( PermissionResolver::class ) ) );

		// The administrator's REST routes.
		$c->set( AgentsController::class, static fn ( Container $c ): AgentsController => new AgentsController( $c ) );
		$c->set( SettingsController::class, static fn ( Container $c ): SettingsController => new SettingsController( $c ) );

		$c->set( Mcp\NotFoundResponse::class, static fn (): Mcp\NotFoundResponse => new Mcp\NotFoundResponse() );
		$c->set(
			AdminMenu::class,
			static fn ( Container $c ): AdminMenu => new AdminMenu( $c->get( Requirements::class ) )
		);
	}
}
