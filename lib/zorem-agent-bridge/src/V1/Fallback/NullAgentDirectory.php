<?php
/**
 * Fallback agent directory.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\AgentDirectoryInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\ProfileProposal;
use ZoremAgentBridge\V1\Dto\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Used when no directory is installed: knows no agents, refuses every proposal.
 */
final class NullAgentDirectory implements AgentDirectoryInterface {

	/**
	 * No agents are known.
	 *
	 * @param string $ref Namespaced reference.
	 */
	public function find( string $ref ): ?AgentRef {
		unset( $ref );

		return null;
	}

	/**
	 * No agent is active, because none exists.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function is_active( AgentRef $agent ): bool {
		unset( $agent );

		return false;
	}

	/**
	 * Every proposal is refused: there is no directory to create agents in.
	 *
	 * @param ProfileProposal $proposal The proposal.
	 * @return Refusal
	 */
	public function ensure_proposed( ProfileProposal $proposal ) {
		unset( $proposal );

		return new Refusal( Refusal::PROVIDER_ABSENT, 'No agent directory is installed.' );
	}
}
