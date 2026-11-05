<?php
/**
 * Contract: what an agent may do.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\Decision;
use ZoremAgentBridge\V1\Dto\ToolSet;

defined( 'ABSPATH' ) || exit;

/**
 * The permission authority. An authority contract: only AI Agent OS provides it.
 */
interface PermissionAuthorityInterface {

	/**
	 * Whether an agent may use an ability at a level.
	 *
	 * @param AgentRef $agent   The agent.
	 * @param string   $ability Ability name.
	 * @param string   $level   "read" or "write".
	 */
	public function decide( AgentRef $agent, string $ability, string $level = 'read' ): Decision;

	/**
	 * Every ability the agent may use. Anything not in the set is invisible to it.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function tools_for( AgentRef $agent ): ToolSet;
}
