<?php
/**
 * A request to pass work to someone else.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * One actor handing a conversation or task to another, or to a person.
 *
 * `to` is either an agent ref or `human`. The summary must already be
 * redacted: it is stored and shown to whoever picks the handoff up.
 */
final class Handoff {

	/** Target meaning "a person at the store". */
	public const TO_HUMAN = 'human';

	/**
	 * Actor handing off.
	 *
	 * @var string
	 */
	private string $from;

	/**
	 * Target: an agent ref or TO_HUMAN.
	 *
	 * @var string
	 */
	private string $to;

	/**
	 * Why, as a slug.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Redacted summary of what is being handed over.
	 *
	 * @var string
	 */
	private string $summary;

	/**
	 * Correlation reference, e.g. an HMAC of the conversation.
	 *
	 * @var string
	 */
	private string $correlation_id;

	/**
	 * Constructor.
	 *
	 * @param string $from           Actor handing off.
	 * @param string $to             Target.
	 * @param string $reason         Why, as a slug.
	 * @param string $summary        Redacted summary.
	 * @param string $correlation_id Correlation reference.
	 * @throws \InvalidArgumentException When a required field is empty.
	 */
	public function __construct( string $from, string $to, string $reason, string $summary = '', string $correlation_id = '' ) {
		if ( '' === $from || '' === $to || '' === $reason ) {
			throw new \InvalidArgumentException( 'A handoff needs from, to and a reason.' );
		}

		$this->from           = $from;
		$this->to             = $to;
		$this->reason         = $reason;
		$this->summary        = mb_substr( $summary, 0, 2000 );
		$this->correlation_id = $correlation_id;
	}

	/**
	 * Actor handing off.
	 */
	public function from(): string {
		return $this->from;
	}

	/**
	 * Target.
	 */
	public function to(): string {
		return $this->to;
	}

	/**
	 * Why, as a slug.
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * Redacted summary.
	 */
	public function summary(): string {
		return $this->summary;
	}

	/**
	 * Correlation reference.
	 */
	public function correlation_id(): string {
		return $this->correlation_id;
	}
}
