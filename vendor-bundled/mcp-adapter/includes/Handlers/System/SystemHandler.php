<?php
/**
 * System method handlers for MCP requests.
 *
 * @package McpAdapter
 */

declare( strict_types=1 );

namespace WP\MCP\Handlers\System;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;

/**
 * Handles system-related MCP methods.
 */
class SystemHandler {
	/**
	 * Handle the ping request.
	 *
	 * @param int|string $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function ping( $request_id = 0 ): array {
		// According to MCP specification, ping returns an empty result.
		return array();
	}

	/**
	 * Handle the logging/setLevel request.
	 *
	 * @param array $params     Request parameters.
	 * @param int|string $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function set_logging_level( array $params, $request_id = 0 ): array {
		if ( ! isset( $params['params']['level'] ) && ! isset( $params['level'] ) ) {
			return array( 'error' => McpErrorFactory::missing_parameter( $request_id, 'level' )['error'] );
		}

		// @todo: Implement logging level setting logic here.

		return array();
	}

	/**
	 * Handle the completion/complete request.
	 *
	 * @param int|string $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function complete( $request_id = 0 ): array {
		// Implement completion logic here.

		return array();
	}

	/**
	 * Handle the roots/list request.
	 *
	 * @param int|string $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function list_roots( $request_id = 0 ): array {
		// Implement roots listing logic here.
		$roots = array();

		return array(
			'roots' => $roots,
		);
	}

	/**
	 * Handle method not found errors.
	 *
	 * @param array $params     Request parameters.
	 * @param int|string $request_id The request ID for JSON-RPC.
	 *
	 * @return array
	 */
	public function method_not_found( array $params, $request_id = 0 ): array {
		$method = $params['method'] ?? 'unknown';

		return array( 'error' => McpErrorFactory::method_not_found( $request_id, $method )['error'] );
	}
}
