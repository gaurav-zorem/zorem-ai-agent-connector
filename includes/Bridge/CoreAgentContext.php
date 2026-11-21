<?php
/**
 * Core's answer to "who is acting", for the bridge.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Bridge;

use ZoremAgentBridge\V1\Contracts\AgentContextProviderInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Auth\AgentContext;

defined( 'ABSPATH' ) || exit;

/**
 * The bridge's agent context, backed by `Auth\AgentContext`.
 *
 * `run_as()` accepts any identity. An `agent:<uuid>` is looked up, so the gate
 * governs it like the agent it is, with the identity taken from the record and
 * never from the caller's reference. A reference this plugin does not know
 * (`exp:*`, say) runs with no agent record, which keeps the gate transparent
 * for it. An `agent:` reference that is not the agent's also runs with no
 * record: the gate sees no agent rather than borrowing anyone's rights.
 */
final class CoreAgentContext implements AgentContextProviderInterface {

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
	 * The acting identity, or null.
	 */
	public function current(): ?AgentRef {
		return AgentContext::current_ref();
	}

	/**
	 * Run a callback as an identity, restored afterwards whatever happens.
	 *
	 * @param AgentRef $agent    The identity.
	 * @param callable $callback Work.
	 * @return mixed
	 */
	public function run_as( AgentRef $agent, callable $callback ) {
		$record = null;

		if ( 0 === strpos( $agent->ref(), Agent::REF_PREFIX ) ) {
			$record = $this->agents->get_by_ref( $agent->ref() );
			$agent  = $record instanceof Agent ? $record->to_agent_ref() : $agent;
		}

		return AgentContext::run_as( $agent, $record, $callback );
	}
}
