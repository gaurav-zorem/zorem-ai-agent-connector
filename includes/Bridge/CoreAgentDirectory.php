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
 * The agent is the Admin Assistant, set up with this plugin and configured by
 * an administrator. Other plugins can ask who it is and whether it is active,
 * and are pointed at it when they propose tools they need.
 */
final class CoreAgentDirectory implements AgentDirectoryInterface {

	/**
	 * Agent storage.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agent storage.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * The agent by reference, or null.
	 *
	 * @param string $ref `agent:<uuid>`.
	 */
	public function find( string $ref ): ?AgentRef {
		$agent = $this->record( $ref );

		return $agent instanceof Agent ? $agent->to_agent_ref() : null;
	}

	/**
	 * Whether the agent is active: not pending, rejected, revoked, suspended or expired.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function is_active( AgentRef $agent ): bool {
		$record = $this->record( $agent->ref() );

		return $record instanceof Agent && $record->is_active();
	}

	/**
	 * The agent a plugin should use when it proposes the tools it needs.
	 *
	 * A proposal is a request, not an instruction: it changes no grant. The
	 * plugin is given the site's agent, and an administrator decides on its
	 * Permissions tab which of the proposed tools it may use.
	 *
	 * @param ProfileProposal $proposal The proposal.
	 * @return AgentRef|Refusal
	 */
	public function ensure_proposed( ProfileProposal $proposal ) {
		unset( $proposal );

		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent ) {
			return new Refusal(
				'agent_not_set_up',
				__( 'The agent has not been set up yet. It is set up the first time an administrator opens the WordPress admin after activating the plugin.', 'zorem-ai-agent-connector' )
			);
		}

		return $agent->to_agent_ref();
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

		return $this->agents->get_by_ref( $ref );
	}
}
