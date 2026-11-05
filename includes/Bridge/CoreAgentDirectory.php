<?php
/**
 * The bridge's agent directory, provided by this plugin.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Bridge;

use ZoremAgentBridge\V1\Contracts\AgentDirectoryInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\ProfileProposal;
use ZoremAgentBridge\V1\Dto\Refusal;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Who the agent is, for other plugins.
 *
 * This site runs one agent, the Admin Assistant, set up with the plugin and
 * configured by an administrator. Another plugin can ask who it is and whether
 * it is active; it cannot ask for an agent of its own, because there is no
 * second agent for this plugin to set up.
 */
final class CoreAgentDirectory implements AgentDirectoryInterface {

	/**
	 * The agents.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents The agents.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * An agent by reference, or null.
	 *
	 * @param string $ref `agent:<uuid>`.
	 */
	public function find( string $ref ): ?AgentRef {
		$agent = $this->record( $ref );

		return $agent instanceof Agent ? $agent->to_agent_ref() : null;
	}

	/**
	 * Whether an agent is active: not pending, rejected, revoked, suspended or expired.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function is_active( AgentRef $agent ): bool {
		$record = $this->record( $agent->ref() );

		return $record instanceof Agent && $record->is_active();
	}

	/**
	 * Agents an installing plugin asks to have set up for itself.
	 *
	 * Not implemented here. This site runs one agent and the administrator
	 * decides what it may do on its Permissions tab, so there is nothing for a
	 * proposal to create and nothing to approve. A plugin that needs access
	 * asks the administrator to grant it to the agent that already exists.
	 *
	 * @param ProfileProposal $proposal The proposal.
	 * @return Refusal
	 */
	public function ensure_proposed( ProfileProposal $proposal ) {
		unset( $proposal );

		return new Refusal(
			'proposals_unsupported',
			__( 'This site runs one agent, set up with the plugin. Ask the administrator to allow the tools you need on its Permissions tab.', 'zorem-ai-agent-connector' )
		);
	}

	/**
	 * The record for a reference.
	 *
	 * @param string $ref Reference.
	 */
	private function record( string $ref ): ?Agent {
		if ( 0 !== strpos( $ref, Agent::REF_PREFIX ) ) {
			return null;
		}

		return $this->agents->find_by_uuid( substr( $ref, strlen( Agent::REF_PREFIX ) ) );
	}
}
