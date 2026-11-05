<?php
/**
 * A refusal with a reason.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Why a request between components was refused: a slug for code and logs,
 * and a sentence for whoever reads it.
 */
final class Refusal {

	/** The provider this request needs is not installed. */
	public const PROVIDER_ABSENT = 'provider_absent';

	/**
	 * Reason slug.
	 *
	 * @var string
	 */
	private string $code;

	/**
	 * Human-readable explanation.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * Constructor.
	 *
	 * @param string $code    Reason slug.
	 * @param string $message Human-readable explanation.
	 * @throws \InvalidArgumentException When no code is given.
	 */
	public function __construct( string $code, string $message = '' ) {
		if ( '' === $code ) {
			throw new \InvalidArgumentException( 'A refusal must carry a code.' );
		}

		$this->code    = $code;
		$this->message = $message;
	}

	/**
	 * Reason slug.
	 */
	public function code(): string {
		return $this->code;
	}

	/**
	 * Human-readable explanation.
	 */
	public function message(): string {
		return $this->message;
	}
}
