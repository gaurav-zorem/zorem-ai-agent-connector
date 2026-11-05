<?php
/**
 * Fallback model access.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Fallback;

use ZoremAgentBridge\V1\Contracts\ModelAccessInterface;
use ZoremAgentBridge\V1\Dto\ModelLease;

defined( 'ABSPATH' ) || exit;

/**
 * Used when nothing provides model access: unavailable.
 */
final class NullModelAccess implements ModelAccessInterface {

	/**
	 * Unavailable.
	 *
	 * @param string $purpose  Purpose.
	 * @param string $audience Audience.
	 */
	public function lease( string $purpose, string $audience ): ModelLease {
		unset( $purpose, $audience );

		return new ModelLease( ModelLease::UNAVAILABLE );
	}
}
