<?php
/**
 * Contract: how to use the tools.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\SkillManual;

defined( 'ABSPATH' ) || exit;

/**
 * Approved skill manuals for an agent. Provided by AI Agent OS.
 */
interface SkillCatalogInterface {

	/**
	 * The approved manuals the agent may read. Drafts are never returned.
	 *
	 * @param AgentRef $agent The agent.
	 * @return array<int, SkillManual>
	 */
	public function manuals_for( AgentRef $agent ): array;
}
