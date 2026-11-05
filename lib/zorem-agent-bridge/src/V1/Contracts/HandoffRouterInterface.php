<?php
/**
 * Contract: passing work between actors.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\Handoff;
use ZoremAgentBridge\V1\Dto\HandoffReceipt;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts, checks and records handoffs. Provided by AI Agent OS.
 */
interface HandoffRouterInterface {

	/**
	 * Request a handoff.
	 *
	 * @param Handoff $handoff The handoff.
	 */
	public function request( Handoff $handoff ): HandoffReceipt;
}
