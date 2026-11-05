<?php
/**
 * In-request agent context.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Context;

use ZoremAgentBridge\V1\Contracts\AgentContextProviderInterface;
use ZoremAgentBridge\V1\Dto\AgentRef;

defined( 'ABSPATH' ) || exit;

/**
 * The context used when no authority provides one: a stack held for the request.
 *
 * Each run_as() pushes, runs and pops in a `finally`, so nesting works and neither
 * an early return nor an exception can leave an agent in context. The legacy
 * storefront reset its context outside a `finally`, and an early return left
 * the shop context set for the rest of the request.
 */
final class LocalAgentContext implements AgentContextProviderInterface {

	/**
	 * Agents in context, innermost last.
	 *
	 * @var array<int, AgentRef>
	 */
	private array $stack = array();

	/**
	 * The innermost agent in context.
	 */
	public function current(): ?AgentRef {
		$top = end( $this->stack );

		return $top instanceof AgentRef ? $top : null;
	}

	/**
	 * Run a callback as an agent.
	 *
	 * @param AgentRef $agent    The agent.
	 * @param callable $callback Work to do.
	 * @return mixed
	 */
	public function run_as( AgentRef $agent, callable $callback ) {
		$this->stack[] = $agent;

		try {
			return $callback();
		} finally {
			array_pop( $this->stack );
		}
	}
}
