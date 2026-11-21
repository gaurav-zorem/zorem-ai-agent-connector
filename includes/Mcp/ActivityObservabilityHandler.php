<?php
/**
 * MCP observability handler.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Receives the adapter's telemetry.
 *
 * The adapter emits events for server creation, tool registration and request
 * handling, and accepts a handler class per server. That is the natural seam
 * for Pillar 5's MCP Analytics — request volumes, which abilities get used,
 * when the agent connects and how often.
 *
 * Phase 1 re-broadcasts the events as a WordPress action and stops there. The
 * ability-level audit trail comes from AbilityGate, which sees every call on
 * every door rather than only the ones that arrive through our server, so
 * nothing here is load-bearing for the activity log.
 *
 * The adapter instantiates this class by name, so it must have no constructor
 * arguments.
 */
final class ActivityObservabilityHandler implements McpObservabilityHandlerInterface {

	/**
	 * Record an adapter event.
	 *
	 * @param string               $event       Event name, e.g. mcp.tool.execute.
	 * @param array<string, mixed> $tags        Event tags.
	 * @param float|null           $duration_ms Duration where measured.
	 */
	public function record_event( string $event, array $tags = array(), ?float $duration_ms = null ): void {
		/**
		 * Fires for every MCP adapter observability event.
		 *
		 * The hook Phase 5 analytics will consume.
		 *
		 * @param string               $event       Event name.
		 * @param array<string, mixed> $tags        Event tags.
		 * @param float|null           $duration_ms Duration in milliseconds.
		 */
		do_action( 'ai_agent_os_mcp_event', $event, $tags, $duration_ms );
	}
}
