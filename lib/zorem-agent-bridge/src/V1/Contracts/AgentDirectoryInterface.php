<?php
/**
 * Contract: who the agents are.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\ProfileProposal;
use ZoremAgentBridge\V1\Dto\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * The directory of agents. An authority contract: only AI Agent OS provides it.
 */
interface AgentDirectoryInterface {

	/**
	 * Find an agent by its namespaced reference.
	 *
	 * @param string $ref Namespaced reference, e.g. "agent:<uuid>".
	 */
	public function find( string $ref ): ?AgentRef;

	/**
	 * Whether an agent is active: not revoked, suspended or expired.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function is_active( AgentRef $agent ): bool;

	/**
	 * Create or return the agent a plugin proposes.
	 *
	 * Idempotent per (owner, slug). The authority decides what is granted; the
	 * proposal is a request, not an instruction.
	 *
	 * @param ProfileProposal $proposal The proposal.
	 * @return AgentRef|Refusal
	 */
	public function ensure_proposed( ProfileProposal $proposal );
}
