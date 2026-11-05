<?php
/**
 * Contract: where audit records go.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\AuditEvent;

defined( 'ABSPATH' ) || exit;

/**
 * The activity log. Provided by AI Agent OS.
 */
interface AuditSinkInterface {

	/**
	 * Record an event.
	 *
	 * @param AuditEvent $event The event.
	 * @return bool True when the sink accepted it; false tells the caller to use its own log.
	 */
	public function record( AuditEvent $event ): bool;
}
