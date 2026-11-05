<?php
/**
 * Fallback permission authority.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\PermissionAuthorityInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;
use ZoremAgentBridge\V1\Dto\Decision;
use ZoremAgentBridge\V1\Dto\ToolSet;

defined( 'ABSPATH' ) || exit;

/**
 * Used when no authority is installed, or when two claimed it: denies everything.
 *
 * Fail closed. A plugin running alone does not ask this authority about its
 * own purpose-built tools — it governs those itself — so denying here costs a
 * standalone plugin nothing and protects everything else.
 */
final class NullPermissionAuthority implements PermissionAuthorityInterface {

	/**
	 * Always denied.
	 *
	 * @param AgentRef $agent   The agent.
	 * @param string   $ability Ability name.
	 * @param string   $level   Level.
	 */
	public function decide( AgentRef $agent, string $ability, string $level = 'read' ): Decision {
		unset( $agent, $ability, $level );

		return Decision::deny( Decision::NO_AUTHORITY );
	}

	/**
	 * No tools.
	 *
	 * @param AgentRef $agent The agent.
	 */
	public function tools_for( AgentRef $agent ): ToolSet {
		unset( $agent );

		return ToolSet::none();
	}
}
