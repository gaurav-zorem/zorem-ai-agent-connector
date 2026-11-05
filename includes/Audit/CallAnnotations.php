<?php
/**
 * A one-call scratchpad for things only the ability knows.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Audit;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an ability contribute context to its own activity row.
 *
 * `AbilityGate` writes the log entry, and it deliberately knows nothing about
 * what any particular ability does — it can derive an object id from the input
 * and an error code from the result, and that is all it should be able to do.
 *
 * The cart abilities need one thing in the log that is visible nowhere in their
 * input or their output: which visitor session was touched. The session id is
 * not a parameter (that is the whole point of §3.1 — the cookie is the only
 * session that can be reached), and it must never appear in the log verbatim,
 * because a log full of live session ids is a hijack shopping list for anyone
 * who can read the admin screen.
 *
 * So the ability computes a keyed hash of it and drops it here; the gate drains
 * whatever is waiting and merges it into the row it was going to write anyway.
 *
 * **The bag is drained twice per call — before and after.** Draining before
 * execution discards anything a previous call left behind when it threw, so one
 * ability's annotation can never be attributed to another's row. That failure
 * mode is not hypothetical in this codebase: the activity buffer leaked across
 * tests for exactly this reason, and the fix was the same shape.
 */
final class CallAnnotations {

	/**
	 * Pending annotations for the call in progress.
	 *
	 * @var array<string, mixed>
	 */
	private array $pending = array();

	/**
	 * Record something about the call in progress.
	 *
	 * @param string $key   Context key.
	 * @param mixed  $value Context value. Must be safe to write to the log.
	 */
	public function note( string $key, $value ): void {
		if ( '' === $key ) {
			return;
		}

		$this->pending[ $key ] = $value;
	}

	/**
	 * Take everything waiting and reset.
	 *
	 * @return array<string, mixed>
	 */
	public function drain(): array {
		$pending = $this->pending;

		$this->pending = array();

		return $pending;
	}
}
