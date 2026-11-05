<?php
/**
 * Fallback handoff router.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\HandoffRouterInterface;
use ZoremAgentBridge\V1\Dto\Handoff;
use ZoremAgentBridge\V1\Dto\HandoffReceipt;

defined( 'ABSPATH' ) || exit;

/**
 * Used when no router is installed: refuses, so the caller uses its own fallback.
 */
final class NullHandoffRouter implements HandoffRouterInterface {

	/**
	 * Refused.
	 *
	 * @param Handoff $handoff The handoff.
	 */
	public function request( Handoff $handoff ): HandoffReceipt {
		unset( $handoff );

		return HandoffReceipt::refused( HandoffReceipt::NO_ROUTER );
	}
}
