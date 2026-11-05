<?php
/**
 * Clearer answers for a tool the agent cannot see.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps a "not found" JSON-RPC error from reading as a dead session.
 *
 * The MCP adapter sends its not-found errors — tool, resource, prompt, method
 * — with HTTP 404. Under the Streamable HTTP transport a 404 is how a server
 * says the session is gone, so a client that calls a tool its agent no longer
 * holds reports "Session terminated" instead of the reason. That is exactly
 * what an administrator removing a permission produces: the tool leaves the
 * agent's server on the next request, and the next call to it 404s.
 *
 * On this plugin's MCP route only, such an error goes out as HTTP 200 — the
 * status JSON-RPC errors are meant to travel with — and a missing tool's
 * message says why it is missing. Nothing else is touched: any other status,
 * any other error code, and anything on another route pass through as they
 * came, so however an adapter version reports a session that really has
 * expired, the client still hears it.
 */
final class NotFoundResponse {

	/**
	 * JSON-RPC error codes the adapter maps to HTTP 404.
	 *
	 * Resource, tool and prompt not found, and method not found.
	 */
	private const NOT_FOUND_CODES = array( -32002, -32003, -32004, -32601 );

	/**
	 * The adapter's code for a tool that is not on the server.
	 */
	private const TOOL_NOT_FOUND = -32003;

	/**
	 * Hook the rewrite.
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'filter' ), 20, 3 );
	}

	/**
	 * Rewrite a 404 not-found JSON-RPC error on the MCP route.
	 *
	 * @param \WP_HTTP_Response $response Result to send.
	 * @param \WP_REST_Server   $server   Server instance.
	 * @param \WP_REST_Request  $request  Request used to generate the response.
	 * @return \WP_HTTP_Response
	 */
	public function filter( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_HTTP_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		// An agent's MCP address.
		if ( 404 !== $response->get_status() || ! Routes::is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		// A single response, or a batch of them.
		$is_batch  = array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 );
		$responses = $is_batch ? $data : array( $data );

		foreach ( $responses as $index => $message ) {
			if ( ! $this->is_not_found( $message ) ) {
				// Something other than a not-found error is in here: leave it all alone.
				return $response;
			}

			$responses[ $index ] = $this->explain( $message );
		}

		$response->set_data( $is_batch ? $responses : $responses[0] );
		$response->set_status( 200 );

		return $response;
	}

	/**
	 * Whether a message is a JSON-RPC not-found error.
	 *
	 * @param mixed $message One JSON-RPC message.
	 */
	private function is_not_found( $message ): bool {
		return is_array( $message )
			&& isset( $message['jsonrpc'], $message['error'] )
			&& is_array( $message['error'] )
			&& in_array( (int) ( $message['error']['code'] ?? 0 ), self::NOT_FOUND_CODES, true );
	}

	/**
	 * Replace a missing tool's message with one that says why.
	 *
	 * @param array<string, mixed> $message A not-found error.
	 * @return array<string, mixed>
	 */
	private function explain( array $message ): array {
		if ( self::TOOL_NOT_FOUND !== (int) $message['error']['code'] ) {
			return $message;
		}

		$original = (string) ( $message['error']['message'] ?? '' );
		$colon    = strrpos( $original, ':' );
		$tool     = false === $colon ? '' : trim( substr( $original, $colon + 1 ) );

		$message['error']['message'] = '' === $tool
			? __( 'That tool is not available to this agent. An administrator may have turned its permission off, or the plugin that provides it may be inactive.', 'zorem-ai-agent-connector' )
			: sprintf(
				/* translators: %s: tool name. */
				__( 'The tool "%s" is not available to this agent. An administrator may have turned its permission off, or the plugin that provides it may be inactive.', 'zorem-ai-agent-connector' ),
				$tool
			);

		return $message;
	}
}
