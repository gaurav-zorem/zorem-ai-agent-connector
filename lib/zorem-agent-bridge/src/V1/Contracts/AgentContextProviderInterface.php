<?php
/**
 * Contract: who is acting right now.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\AgentRef;

defined( 'ABSPATH' ) || exit;

/**
 * The acting agent for the current request. An authority contract.
 *
 * Context is only ever changed through run_as(), which restores the previous
 * context in a `finally` — so an early return or an exception inside the
 * callback can never leave an agent in context for the rest of the request.
 */
interface AgentContextProviderInterface {

	/**
	 * The acting agent, or null when no agent is in context.
	 */
	public function current(): ?AgentRef;

	/**
	 * Run a callback as an agent, restoring the previous context afterwards.
	 *
	 * @param AgentRef $agent    The agent to act as.
	 * @param callable $callback Work to do.
	 * @return mixed The callback's return value.
	 */
	public function run_as( AgentRef $agent, callable $callback );
}
