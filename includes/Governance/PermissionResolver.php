<?php
/**
 * The permission authority.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use ZoremAgentBrain\V1\Brain;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Brain\AgentReaders;
use AiAgentOs\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what the agent may do.
 *
 * This is the single authority. Both enforcement points call it: the MCP
 * server builder, to decide which tools the agent can even see, and AbilityGate,
 * to decide whether a call may proceed. Neither is trusted on its own, and
 * neither reimplements the rules.
 *
 * Every path defaults to deny. A grant has to be affirmatively present, the
 * agent has to be active and unexpired, and the grant level has to meet the
 * ability's risk. Destructive abilities need three separate yeses.
 */
final class PermissionResolver {

	/**
	 * Grant storage.
	 *
	 * @var PermissionRepository
	 */
	private PermissionRepository $permissions;

	/**
	 * Risk classification.
	 *
	 * @var RiskClassifier
	 */
	private RiskClassifier $risk;

	/**
	 * Ability registry access.
	 *
	 * @var AbilityInventory
	 */
	private AbilityInventory $inventory;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The agent's Brain reader, for Brain entries exposed as MCP resources.
	 *
	 * @var AgentReaders
	 */
	private AgentReaders $readers;

	/**
	 * Which abilities the gate wrapped; null when nothing records it.
	 *
	 * @var GovernedAbilities|null
	 */
	private ?GovernedAbilities $governed;

	/**
	 * Constructor.
	 *
	 * @param PermissionRepository $permissions Grant storage.
	 * @param RiskClassifier       $risk        Risk classification.
	 * @param AbilityInventory     $inventory   Ability registry access.
	 * @param Settings             $settings    Plugin settings.
	 * @param AgentReaders         $readers     The agent's Brain reader.
	 * @param GovernedAbilities    $governed    Which abilities the gate wrapped.
	 */
	public function __construct(
		PermissionRepository $permissions,
		RiskClassifier $risk,
		AbilityInventory $inventory,
		Settings $settings,
		AgentReaders $readers,
		?GovernedAbilities $governed = null
	) {
		$this->permissions = $permissions;
		$this->risk        = $risk;
		$this->inventory   = $inventory;
		$this->settings    = $settings;
		$this->readers     = $readers;
		$this->governed    = $governed;
	}

	/**
	 * Decide whether the agent may use an ability.
	 *
	 * @param Agent       $agent   The agent.
	 * @param \WP_Ability $ability The ability.
	 * @param mixed       $input   The call's input, when deciding a call rather than a listing.
	 */
	public function resolve( Agent $agent, \WP_Ability $ability, $input = null ): Decision {
		$ability_name = $ability->get_name();

		// 1. Some abilities are off-limits to the agent, granted or not.
		if ( $this->inventory->is_hidden( $ability_name ) ) {
			return $this->filter( Decision::deny( Decision::HIDDEN_ABILITY ), $agent, $ability );
		}

		// An ability the gate never wrapped would run with no masking, audit or nested gating.
		if ( null !== $this->governed && ! $this->governed->is_governed( $ability_name ) ) {
			return $this->filter( Decision::deny( Decision::UNGOVERNED ), $agent, $ability );
		}

		// 2. The agent itself must be able to act at all.
		$inactive = $agent->inactive_reason();

		if ( null !== $inactive ) {
			return $this->filter( Decision::deny( $inactive ), $agent, $ability );
		}

		/*
		 * A Brain entry exposed as an MCP resource is not granted per ability:
		 * whether the agent may read it is the Brain's decision, made by the
		 * gate for the agent's reader.
		 */
		$entry_key = $this->brain_entry_of( $ability );

		if ( null !== $entry_key ) {
			$readable = null !== Brain::gate()->get( $this->readers->for_agent( $agent ), $entry_key );

			return $this->filter( $readable ? Decision::allow( PermissionRepository::LEVEL_READ ) : Decision::deny( Decision::NOT_GRANTED ), $agent, $ability );
		}

		// 3. There must be an affirmative grant. Absence is denial.
		$level = $this->permissions->get_grant( $agent->get_id(), $ability_name );

		if ( PermissionRepository::LEVEL_NONE === $level ) {
			return $this->filter( Decision::deny( Decision::NOT_GRANTED ), $agent, $ability );
		}

		// 4. The grant must be strong enough for what the ability does.
		$risk     = $this->risk->classify( $ability );
		$required = RiskClassifier::required_level( $risk );

		if ( ! $this->level_satisfies( $level, $required ) ) {
			return $this->filter( Decision::deny( Decision::GRANT_TOO_LOW, $level ), $agent, $ability );
		}

		/*
		 * 5. Destructive abilities need the site and the agent to both opt in.
		 * So does a write call whose input does something irreversible: an
		 * order status set to cancelled or refunded is a cancel or a refund.
		 */
		if ( RiskClassifier::RISK_WRITE === $risk && RiskClassifier::input_is_destructive( $input ) ) {
			$risk = RiskClassifier::RISK_DESTRUCTIVE;
		}

		if ( RiskClassifier::RISK_DESTRUCTIVE === $risk && ! $this->destructive_allowed( $agent ) ) {
			return $this->filter( Decision::deny( Decision::DESTRUCTIVE_DISABLED, $level ), $agent, $ability );
		}

		return $this->filter( Decision::allow( $level ), $agent, $ability );
	}

	/**
	 * Whether the agent may use an ability, by name.
	 *
	 * @param Agent  $agent        The agent.
	 * @param string $ability_name Ability name.
	 */
	public function resolve_by_name( Agent $agent, string $ability_name ): Decision {
		$ability = $this->inventory->get( $ability_name );

		if ( ! $ability instanceof \WP_Ability ) {
			return Decision::deny( Decision::NOT_GRANTED );
		}

		return $this->resolve( $agent, $ability );
	}

	/**
	 * Every ability this agent may both see and call.
	 *
	 * The MCP server is built from exactly this list, which is what makes
	 * ungranted tools invisible rather than merely rejected: they are never
	 * registered on the agent's server, so tools/list cannot report them and
	 * the agent cannot be talked into trying one it does not know about.
	 *
	 * @param Agent $agent The agent.
	 * @return array<int, string> Ability names.
	 */
	public function granted_ability_names( Agent $agent ): array {
		if ( ! $agent->is_active() ) {
			return array();
		}

		$granted = array();

		foreach ( $this->inventory->grantable() as $name => $ability ) {
			if ( $this->resolve( $agent, $ability )->is_allowed() ) {
				$granted[] = (string) $name;
			}
		}

		return $granted;
	}

	/**
	 * The entry key of a Brain resource ability, or null.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	private function brain_entry_of( \WP_Ability $ability ): ?string {
		$mcp   = $ability->get_meta_item( 'mcp', array() );
		$zorem = $ability->get_meta_item( 'zorem', array() );

		if ( is_array( $mcp ) && 'resource' === ( $mcp['type'] ?? '' ) && is_array( $zorem ) && ! empty( $zorem['entry_key'] ) && 0 === strpos( $ability->get_name(), 'ai-agent-os/kb-' ) && self::registered_here( $ability->get_name() ) ) {
			return (string) $zorem['entry_key'];
		}

		return null;
	}

	/**
	 * Whether this plugin's own files registered an ability.
	 *
	 * Brain resources are allowed by derivation, with no grant, so their names
	 * alone cannot be trusted: another plugin could register a predictable
	 * `ai-agent-os/kb-*` name first and have its own callback run as derived
	 * access. The Brain's
	 * origin recorder saw which file registered it.
	 *
	 * @param string $name Ability name.
	 */
	private static function registered_here( string $name ): bool {
		$origin = Brain::get( \ZoremAgentBrain\V1\Extraction\AbilityOriginRecorder::class )->origin_of( $name );

		return null !== $origin && 0 === strpos( wp_normalize_path( $origin ), wp_normalize_path( AI_AGENT_OS_PATH . 'includes/' ) );
	}

	/**
	 * Whether a held grant level meets a required one.
	 *
	 * @param string $held     The agent's grant level.
	 * @param string $required The level the ability needs.
	 */
	/**
	 * Whether a held grant level is at least the required one.
	 *
	 * @param string $held     Level held.
	 * @param string $required Level required.
	 */
	private function level_satisfies( string $held, string $required ): bool {
		$rank = array(
			PermissionRepository::LEVEL_NONE  => 0,
			PermissionRepository::LEVEL_READ  => 1,
			PermissionRepository::LEVEL_WRITE => 2,
		);

		return ( $rank[ $held ] ?? 0 ) >= ( $rank[ $required ] ?? 2 );
	}

	/**
	 * Whether destructive abilities may run for this agent.
	 *
	 * The agent's own switch decides. It is off when the agent is set up (no
	 * profile turns it on), so a destructive ability is ungrantable by
	 * default and takes a deliberate act on that agent's page. The site-wide
	 * master switch that also had to be on was removed in 1.0.1.
	 *
	 * @param Agent $agent The agent.
	 */
	public function destructive_allowed( Agent $agent ): bool {
		return $agent->allows_destructive();
	}

	/**
	 * Let a filter refuse what the resolver allowed; never let it allow what the resolver refused.
	 *
	 * The legacy filter could turn any refusal into an allow, which made one
	 * line in any plugin a way around every grant. A site may still add a
	 * reason to say no.
	 *
	 * @param Decision    $decision The resolver's decision.
	 * @param Agent       $agent    The agent.
	 * @param \WP_Ability $ability  The ability.
	 */
	private function filter( Decision $decision, Agent $agent, \WP_Ability $ability ): Decision {
		/**
		 * Filters a permission decision. It may only narrow it: an allow may
		 * become a refusal; a refusal stays a refusal.
		 *
		 * @param Decision    $decision The decision.
		 * @param Agent       $agent    The agent.
		 * @param \WP_Ability $ability  The ability.
		 */
		$filtered = apply_filters( 'ai_agent_os_permission_decision', $decision, $agent, $ability );

		if ( ! $filtered instanceof Decision || ( ! $decision->is_allowed() && $filtered->is_allowed() ) ) {
			return $decision;
		}

		// Nor raise the grant level an allow carries: read stays read.
		if ( $filtered->is_allowed() && ! $this->level_satisfies( $decision->get_grant_level(), $filtered->get_grant_level() ) ) {
			return $decision;
		}

		return $filtered;
	}
}
