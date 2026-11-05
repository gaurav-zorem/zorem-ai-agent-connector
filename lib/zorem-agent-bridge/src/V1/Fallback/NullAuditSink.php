<?php
/**
 * Fallback audit sink.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\AuditSinkInterface;
use ZoremAgentBridge\V1\Dto\AuditEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Used when no activity log is installed: accepts nothing.
 *
 * Returning false, not silently discarding, is the point: the caller learns
 * there is no shared log and writes to its own, so no record is lost.
 */
final class NullAuditSink implements AuditSinkInterface {

	/**
	 * Not accepted.
	 *
	 * @param AuditEvent $event The event.
	 */
	public function record( AuditEvent $event ): bool {
		unset( $event );

		return false;
	}
}
