<?php
/**
 * Core's permission decisions, for the bridge.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Bridge;

use ZoremAgentBridge\V1\Contracts\PermissionAuthorityInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\Decision;
use ZoremAgentBridge\V1\Dto\ToolSet;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Governance\Decision as CoreDecision;
use AiAgentOs\Governance\PermissionResolver;

defined( 'ABSPATH' ) || exit;

/**
 * What the agent may do, asked by another plugin: the same resolver the gate uses.
 *
 * An identity that is not this plugin's agent gets a refusal
 * (`not_an_agent`) and an empty tool set: Core decides for its agent and for
 * nobody else.
 */
final class CorePermissionAuthority implements PermissionAuthorityInterface {

	/** Reason for an identity that is not this plugin's agent. */
	public const NOT_AN_AGENT = 'not_an_agent';

	/**
	 * Agent storage.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * The resolver.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $resolver;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository    $agents   Agent storage.
	 * @param PermissionResolver $resolver The resolver.
	 */
	public function __construct( AgentRepository $agents, PermissionResolver $resolver ) {
		$this->agents   = $agents;
		$this->resolver = $resolver;
	}

	/**
	 * Whether the agent may use an ability at a level.
	 *
	 * @param AgentRef $agent   The agent.
	 * @param string   $ability Ability name.
	 * @param string   $level   "read" or "write".
	 */
	public function decide( AgentRef $agent, string $ability, string $level = 'read' ): Decision {
		$record = $this->record( $agent );

		if ( ! $record instanceof Agent ) {
			return Decision::deny( self::NOT_AN_AGENT );
		}

		$decision = $this->resolver->resolve_by_name( $record, $ability );

		if ( ! $decision->is_allowed() ) {
			return Decision::deny( (string) $decision->get_reason() );
		}

		// Asked for write while holding read: not enough.
		if ( 'write' === $level && 'write' !== $decision->get_grant_level() ) {
			return Decision::deny( CoreDecision::GRANT_TOO_LOW );
		}

		return Decision::allow( $decision->get_grant_level() );
	}

	/**
	 * Every ability the agent may use.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function tools_for( AgentRef $agent ): ToolSet {
		$record = $this->record( $agent );

		return $record instanceof Agent ? new ToolSet( $this->resolver->granted_ability_names( $record ) ) : ToolSet::none();
	}

	/**
	 * The record for a reference.
	 *
	 * @param AgentRef $agent The agent.
	 */
	private function record( AgentRef $agent ): ?Agent {
		if ( 0 !== strpos( $agent->ref(), Agent::REF_PREFIX ) ) {
			return null;
		}

		return $this->agents->get_by_ref( $agent->ref() );
	}
}
