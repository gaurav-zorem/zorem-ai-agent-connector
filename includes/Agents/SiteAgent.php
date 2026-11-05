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
 * Sets up the site's "Admin Assistant" agent the first time the plugin is activated.
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
	 * Agent provisioning.
	 *
	 * @var AgentFactory
	 */
	private AgentFactory $factory;

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
	 * @param AgentFactory         $factory     Agent provisioning.
	 * @param AbilityInventory     $inventory   The grantable abilities.
	 * @param PermissionRepository $permissions Grants.
	 */
	public function __construct( AgentFactory $factory, AbilityInventory $inventory, PermissionRepository $permissions ) {
		$this->factory     = $factory;
		$this->inventory   = $inventory;
		$this->permissions = $permissions;
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
		add_action( 'admin_init', array( $this, 'maybe_provision' ) );
	}

	/**
	 * Set the agent up when it is due and an administrator is here to own the grants.
	 *
	 * @return Agent|null The agent, or null when nothing was due or it failed.
	 */
	public function maybe_provision(): ?Agent {
		if ( 'pending' !== get_option( self::OPTION ) || ! current_user_can( Capabilities::MANAGE ) ) {
			return null;
		}

		$created = $this->factory->provision(
			array(
				'name'        => __( 'Admin Assistant', 'zorem-ai-agent-connector' ),
				'description' => __( 'Set up when this plugin was installed, holding the tools the plugin ships. Change what it may do on its Permissions tab.', 'zorem-ai-agent-connector' ),
				'acts_for'    => Agent::ACTS_FOR_ADMIN,
				'issue_token' => false,
			)
		);

		// Left pending on failure, so the next admin page tries again.
		if ( is_wp_error( $created ) || ! ( $created['agent'] ?? null ) instanceof Agent ) {
			return null;
		}

		$agent = $created['agent'];

		$this->permissions->set_grants( $agent->get_id(), $this->every_grant(), get_current_user_id() );

		update_option( self::OPTION, 'done', false );

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
	 * WooCommerce store the wider set would have handed a brand-new agent
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
