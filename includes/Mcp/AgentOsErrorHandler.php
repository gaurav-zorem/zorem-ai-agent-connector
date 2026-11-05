<?php
/**
 * MCP error handler.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Routes adapter errors somewhere useful without leaking them to clients.
 *
 * The adapter instantiates this class by name, so it must have no constructor
 * arguments.
 */
final class AgentOsErrorHandler implements McpErrorHandlerInterface {

	/**
	 * Log an adapter error.
	 *
	 * Kept behind WP_DEBUG: these messages can contain request details, and a
	 * production error log is not a place to accumulate them by default.
	 *
	 * @param string               $message The log message.
	 * @param array<string, mixed> $context Additional context.
	 * @param string               $type    Log type.
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		/**
		 * Fires when the MCP adapter reports an error.
		 *
		 * @param string               $message The message.
		 * @param array<string, mixed> $context Context data.
		 * @param string               $type    Log type.
		 */
		do_action( 'ai_agent_os_mcp_error', $message, $context, $type );

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic.
		error_log( sprintf( '[ai-agent-os][mcp][%s] %s', $type, $message ) );
	}
}
