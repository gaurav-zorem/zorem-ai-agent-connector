<?php
/**
 * One audit record.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * The single shape every Agent OS audit record takes.
 *
 * AI Agent OS stores it in its activity log; AI Shopping Assistant stores the
 * same shape in its own log when Core is not installed. One shape means a
 * record written by either plugin reads the same in either log, and nothing
 * is lost when records move from one to the other.
 */
final class AuditEvent {

	/** Outcomes. */
	public const OUTCOME_ALLOWED = 'allowed';
	public const OUTCOME_DENIED  = 'denied';
	public const OUTCOME_ERROR   = 'error';

	/** Actor types. */
	public const ACTOR_AGENT  = 'agent';
	public const ACTOR_HUMAN  = 'human';
	public const ACTOR_SYSTEM = 'system';

	/** Longest input summary kept, in characters. */
	private const MAX_SUMMARY = 1000;

	/**
	 * Fields, validated.
	 *
	 * @var array<string, mixed>
	 */
	private array $fields;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $fields Validated fields.
	 */
	private function __construct( array $fields ) {
		$this->fields = $fields;
	}

	/**
	 * Build and validate.
	 *
	 * Required: `source_plugin`, `channel`, `action`, `outcome`. A denial or an
	 * error must carry a `reason`. The input summary is expected to be redacted
	 * already and is bounded here.
	 *
	 * @param array<string, mixed> $data Fields.
	 * @throws \InvalidArgumentException When a required field is missing or out of range.
	 */
	public static function from_array( array $data ): self {
		foreach ( array( 'source_plugin', 'channel', 'action', 'outcome' ) as $required ) {
			if ( '' === (string) ( $data[ $required ] ?? '' ) ) {
				throw new \InvalidArgumentException( sprintf( 'An audit event needs "%s".', esc_html( $required ) ) );
			}
		}

		$outcome = (string) $data['outcome'];

		if ( ! in_array( $outcome, array( self::OUTCOME_ALLOWED, self::OUTCOME_DENIED, self::OUTCOME_ERROR ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown audit outcome.' );
		}

		$reason = (string) ( $data['reason'] ?? '' );

		if ( self::OUTCOME_ALLOWED !== $outcome && '' === $reason ) {
			throw new \InvalidArgumentException( 'A denied or failed action must record its reason.' );
		}

		$actor_type = (string) ( $data['actor_type'] ?? self::ACTOR_AGENT );

		if ( ! in_array( $actor_type, array( self::ACTOR_AGENT, self::ACTOR_HUMAN, self::ACTOR_SYSTEM ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown actor type.' );
		}

		return new self(
			array(
				'created_at'     => (string) ( $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
				'agent_ref'      => (string) ( $data['agent_ref'] ?? '' ),
				'actor_type'     => $actor_type,
				'source_plugin'  => (string) $data['source_plugin'],
				'channel'        => (string) $data['channel'],
				'action'         => (string) $data['action'],
				'object_type'    => (string) ( $data['object_type'] ?? '' ),
				'object_id'      => (string) ( $data['object_id'] ?? '' ),
				'outcome'        => $outcome,
				'reason'         => $reason,
				'input_summary'  => mb_substr( (string) ( $data['input_summary'] ?? '' ), 0, self::MAX_SUMMARY ),
				'request_id'     => (string) ( $data['request_id'] ?? '' ),
				'correlation_id' => (string) ( $data['correlation_id'] ?? '' ),
				'duration_ms'    => max( 0, (int) ( $data['duration_ms'] ?? 0 ) ),
			)
		);
	}

	/**
	 * One field.
	 *
	 * @param string $name Field name.
	 * @return mixed
	 */
	public function get( string $name ) {
		return $this->fields[ $name ] ?? null;
	}

	/**
	 * All fields.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->fields;
	}
}
