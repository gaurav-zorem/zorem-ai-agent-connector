<?php
/**
 * The agent this site runs.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\CoreAbilities;
use AiAgentOs\Abilities\WhoAmI;
use AiAgentOs\Core\Capabilities;
use AiAgentOs\Governance\AbilityInventory;
use AiAgentOs\Governance\PermissionRepository;
use AiAgentOs\Governance\RiskClassifier;

/**
 * The site's agent, the "Admin Assistant", and its one-time setup.
 *
 * Activation only marks it as due: abilities register later than activation
 * runs, and a grant names an ability. The agent is set up on the next admin
 * page an administrator opens, once, and never again — not on reactivation.
 *
 * It starts with every WordPress tool this plugin ships: Read where a tool only
 * reads, Write everywhere else — destructive tools included, which still run
 * only once the agent's destructive switch is on. Tools other plugins register
 * are on the Permissions tab too, at None until an administrator grants them
 * (see `every_grant()`). That is the starting point, not a fixed state: the
 * grants are written once, when the agent is set up, and from then on they are
 * whatever the administrator sets. It acts for the administrator, and it has no
 * token until one is issued on its Connection tab; signing in from Claude or
 * ChatGPT needs none.
 */
final class SiteAgent {

	/**
	 * Option: "pending" from first activation until the agent exists, then "done".
	 *
	 * The name is kept from an earlier release so a site that already holds
	 * "done" is not set up a second time on upgrade.
	 */
	public const OPTION = 'ai_agent_os_first_agent';

	/**
	 * Agent storage.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * The agent's WordPress account.
	 *
	 * @var ShadowUserManager
	 */
	private ShadowUserManager $shadow_users;

	/**
	 * The grantable abilities.
	 *
	 * @var AbilityInventory
	 */
	private AbilityInventory $inventory;

	/**
	 * Grants.
	 *
	 * @var PermissionRepository
	 */
	private PermissionRepository $permissions;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository      $agents       Agent storage.
	 * @param ShadowUserManager    $shadow_users The agent's WordPress account.
	 * @param AbilityInventory     $inventory    The grantable abilities.
	 * @param PermissionRepository $permissions  Grants.
	 */
	public function __construct( AgentRepository $agents, ShadowUserManager $shadow_users, AbilityInventory $inventory, PermissionRepository $permissions ) {
		$this->agents       = $agents;
		$this->shadow_users = $shadow_users;
		$this->inventory    = $inventory;
		$this->permissions  = $permissions;
	}

	/**
	 * Mark the agent as due. Called on activation, before anything is recorded as installed.
	 *
	 * @param bool $first_install Whether this site has never had the plugin installed.
	 */
	public static function mark_due( bool $first_install ): void {
		if ( $first_install && false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, 'pending', '', false );
		}
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_set_up' ) );
	}

	/**
	 * Set the agent up when it is due and an administrator is here to own the grants.
	 *
	 * @return Agent|null The agent, or null when nothing was due or setup failed.
	 */
	public function maybe_set_up(): ?Agent {
		if ( 'pending' !== get_option( self::OPTION ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return null;
		}

		// Already set up (a restored database, say): nothing to create.
		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent ) {
			$agent = $this->create();

			// Left pending on failure, so the next admin page tries again.
			if ( ! $agent instanceof Agent ) {
				return null;
			}

			$this->permissions->set_grants( $agent->get_id(), $this->every_grant(), get_current_user_id() );
		}

		update_option( self::OPTION, 'done', false );

		return $agent;
	}

	/**
	 * Create the agent: its WordPress account and its settings.
	 *
	 * It starts with no token. A token is shown exactly once, when it is
	 * issued, and stored only as a hash, so one minted here would be shown to
	 * nobody. Nothing can authenticate as the agent until an administrator
	 * issues a token on its Connection tab, or signs in from an AI app.
	 */
	private function create(): ?Agent {
		$name = __( 'Admin Assistant', 'zorem-ai-agent-connector' );
		$uuid = wp_generate_uuid4();

		$shadow_user_id = $this->shadow_users->create( $uuid, $name );

		if ( is_wp_error( $shadow_user_id ) ) {
			return null;
		}

		/*
		 * Null token columns, not empty strings: `token_hash` carries a UNIQUE
		 * index, and MySQL exempts NULL from uniqueness.
		 */
		$agent = $this->agents->create(
			array(
				'uuid'           => $uuid,
				'name'           => $name,
				'slug'           => 'admin-assistant',
				'acts_for'       => Agent::ACTS_FOR_ADMIN,
				'status'         => Agent::STATUS_ACTIVE,
				'auth_method'    => 'bearer',
				'shadow_user_id' => (int) $shadow_user_id,
				'meta'           => array(
					'description'         => __( 'Set up when this plugin was installed, holding the tools the plugin ships. Change what it may do on its Permissions tab.', 'zorem-ai-agent-connector' ),
					'destructive_enabled' => false,
					'pii_masked'          => false,
				),
			)
		);

		if ( ! $agent instanceof Agent ) {
			// Do not leave an orphaned user behind when the row failed.
			$this->shadow_users->delete( (int) $shadow_user_id );

			return null;
		}

		/**
		 * Fires after the agent is set up.
		 *
		 * @param Agent $agent The agent.
		 */
		do_action( 'ai_agent_os_agent_created', $agent );

		return $agent;
	}

	/**
	 * The starting grants: every tool this plugin ships, at the most it can be
	 * granted — Read for a read-only tool, Write for the rest. A destructive
	 * tool is granted Write too; the agent's destructive switch decides whether
	 * it runs.
	 *
	 * ## Why only the tools this plugin ships
	 *
	 * The permission matrix lists every ability registered on the site,
	 * whichever plugin registered it, and all of them are grantable. The
	 * opening grants are narrower, and deliberately so: an administrator who
	 * installs this plugin is agreeing to the tools they can read about in its
	 * description, not to whatever else happens to be installed. On a
	 * WooCommerce store the wider set would have handed the newly set-up agent
	 * `product-delete` and `order-update-status` before anyone had looked at
	 * the screen.
	 *
	 * Nothing is withheld. Every other ability sits on the Permissions tab at
	 * None, with its risk rating, one click from being granted.
	 *
	 * @return array<string, string> Ability name => level.
	 */
	public function every_grant(): array {
		$grants = array();

		foreach ( $this->inventory->descriptors() as $name => $descriptor ) {
			if ( ! self::is_shipped_tool( (string) $name ) ) {
				continue;
			}

			$grants[ (string) $name ] = RiskClassifier::RISK_READ === (string) $descriptor['risk'] ? PermissionRepository::LEVEL_READ : PermissionRepository::LEVEL_WRITE;
		}

		return $grants;
	}

	/**
	 * Whether an ability is one this plugin ships: its own WordPress tools, the
	 * who-am-I tool, or WordPress's own `core/*`.
	 *
	 * Used for the opening grants only. It decides nothing about what may be
	 * granted, listed or run.
	 *
	 * @param string $name Ability name.
	 */
	private static function is_shipped_tool( string $name ): bool {
		return 0 === strpos( $name, 'core/' )
			|| WhoAmI::NAME === $name
			|| null !== CoreAbilities::class_for( $name );
	}
}
