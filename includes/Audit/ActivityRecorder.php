<?php
/**
 * The call journal.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Audit;

use AiAgentOs\Agents\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * The call journal: an extension point that keeps nothing.
 *
 * This plugin keeps no record of the agent's calls — no table rows, no buffer,
 * no options, nothing sent anywhere. The permission gate and the MCP transport
 * still report every call and every refusal here, so a site that wants a record
 * has one place to take it from, and the governance code does not have to know
 * whether anybody is listening.
 */
final class ActivityRecorder {

	public const STATUS_SUCCESS = 'success';
	public const STATUS_ERROR   = 'error';

	/** What kind of call is being reported. */
	public const KIND_READ   = 'read';
	public const KIND_CHANGE = 'change';

	/**
	 * Nothing to flush, so nothing to hook.
	 */
	public function register(): void {}

	/**
	 * A refusal. Kept nowhere.
	 *
	 * @param Agent|null           $agent        The agent.
	 * @param string               $ability_name Ability refused.
	 * @param string               $reason       Reason slug.
	 * @param mixed                $input        The input.
	 * @param array<string, mixed> $context      Extra context.
	 */
	public function blocked( ?Agent $agent, string $ability_name, string $reason, $input = null, array $context = array() ): void {}

	/**
	 * A completed call. Kept nowhere.
	 *
	 * @param Agent|null           $agent        The agent.
	 * @param string               $ability_name Ability executed.
	 * @param string               $status       STATUS_SUCCESS or STATUS_ERROR.
	 * @param mixed                $input        The input.
	 * @param int|null             $duration_ms  How long it took.
	 * @param array<string, mixed> $context      Extra context.
	 */
	public function executed( ?Agent $agent, string $ability_name, string $status, $input = null, ?int $duration_ms = null, array $context = array() ): void {}

	/**
	 * Nothing is buffered.
	 */
	public function flush(): void {}
}
