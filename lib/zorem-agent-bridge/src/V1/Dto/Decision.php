<?php
/**
 * A permission decision.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Whether an actor may use an ability, and why.
 *
 * A bare boolean loses the reason, and the reason is what the activity log
 * exists to show. A denial always carries a slug.
 */
final class Decision {

	/** Reason when no permission authority is installed or one is in conflict. */
	public const NO_AUTHORITY = 'no_authority';

	/**
	 * Whether the action may proceed.
	 *
	 * @var bool
	 */
	private bool $allowed;

	/**
	 * Reason slug; empty when allowed.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * The grant level that applied.
	 *
	 * @var string
	 */
	private string $level;

	/**
	 * Constructor.
	 *
	 * @param bool   $allowed Whether the action may proceed.
	 * @param string $reason  Reason slug.
	 * @param string $level   Grant level.
	 */
	private function __construct( bool $allowed, string $reason, string $level ) {
		$this->allowed = $allowed;
		$this->reason  = $reason;
		$this->level   = $level;
	}

	/**
	 * An allowing decision.
	 *
	 * @param string $level The grant level that applied.
	 */
	public static function allow( string $level = 'read' ): self {
		return new self( true, '', $level );
	}

	/**
	 * A denying decision.
	 *
	 * @param string $reason Reason slug.
	 * @throws \InvalidArgumentException When no reason is given.
	 */
	public static function deny( string $reason ): self {
		if ( '' === $reason ) {
			throw new \InvalidArgumentException( 'A denial must carry a reason.' );
		}

		return new self( false, $reason, 'none' );
	}

	/**
	 * Whether the action may proceed.
	 */
	public function allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Reason slug; empty when allowed.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * The grant level that applied.
	 */
	public function level(): string {
		return $this->level;
	}
}
