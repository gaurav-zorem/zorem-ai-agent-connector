<?php
/**
 * The answer to a handoff request.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a handoff was accepted, and its id or the reason it was not.
 */
final class HandoffReceipt {

	/** Reason when no handoff router is installed. */
	public const NO_ROUTER = 'no_handoff_router';

	/**
	 * Whether the handoff was accepted.
	 *
	 * @var bool
	 */
	private bool $accepted;

	/**
	 * Handoff id when accepted.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Reason slug when refused.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param bool   $accepted Whether the handoff was accepted.
	 * @param string $id       Handoff id.
	 * @param string $reason   Reason slug when refused.
	 */
	private function __construct( bool $accepted, string $id, string $reason ) {
		$this->accepted = $accepted;
		$this->id       = $id;
		$this->reason   = $reason;
	}

	/**
	 * An accepted handoff.
	 *
	 * @param string $id Handoff id.
	 */
	public static function accepted( string $id ): self {
		return new self( true, $id, '' );
	}

	/**
	 * A refused handoff.
	 *
	 * @param string $reason Reason slug.
	 */
	public static function refused( string $reason ): self {
		return new self( false, '', '' !== $reason ? $reason : self::NO_ROUTER );
	}

	/**
	 * Whether the handoff was accepted.
	 */
	public function is_accepted(): bool {
		return $this->accepted;
	}

	/**
	 * Handoff id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Reason slug when refused.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
