<?php
/**
 * Fallback skill catalog.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\SkillCatalogInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;

defined( 'ABSPATH' ) || exit;

/**
 * Used when no skills platform is installed: no manuals.
 */
final class NullSkillCatalog implements SkillCatalogInterface {

	/**
	 * No manuals.
	 *
	 * @param AgentRef $agent The agent.
	 * @return array<int, \ZoremAgentBridge\V1\Dto\SkillManual>
	 */
	public function manuals_for( AgentRef $agent ): array {
		unset( $agent );

		return array();
	}
}
