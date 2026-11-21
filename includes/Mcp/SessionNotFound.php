<?php
/**
 * HTTP 404 for an MCP session the server no longer knows.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Auth\AgentContext;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a call on an unknown or expired MCP session with HTTP 404.
 *
 * The MCP specification (Streamable HTTP, session management) says a server
 * that no longer knows a request's `Mcp-Session-Id` MUST answer 404, and a
 * client that gets that 404 MUST start a new session with `initialize`. The
 * bundled MCP Adapter answers 200 with a JSON-RPC error instead, so a client
 * holding a session the site has forgotten never starts a new one: it reports
 * the reply as a failed connection. Claude showed it as "returned an error
 * when connecting" when a connector was reconnected.
 *
 * A session is forgotten when the client ends it (DELETE), after a day without
 * calls, or when the agent's account passes the adapter's 32 sessions and the
 * oldest is dropped, which can be one a client is still using.
 *
 * Done here rather than in `vendor-bundled/mcp-adapter/`, which is replaced
 * whole on every update. Only for an agent that may act: an unauthenticated
 * or refused request is left to the transport, which answers 401 or 403.
 */
final class SessionNotFound {

	/**
	 * JSON-RPC error code MCP clients and SDKs use for "session not found".
	 */
	public const ERROR_CODE = -32001;

	/**
	 * Hook the check.
	 */
	public function register(): void {
		// Priority 1: after AgentAuthenticator's shadow-user refusal (0), before ActivityNotes (5) and RequestJournal (10), so a dead session leaves no rows.
		add_filter( 'rest_pre_dispatch', array( $this, 'check' ), 1, 3 );
	}

	/**
	 * Answer 404 when the request names a session this agent's account does not have.
	 *
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function check( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result || ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return $result;
		}

		if ( ! Routes::is_mcp_route( $request->get_route() ) || ! class_exists( '\WP\MCP\Transport\Infrastructure\SessionManager' ) ) {
			return $result;
		}

		$agent   = AgentContext::get();
		$user_id = get_current_user_id();

		// Only an agent that may act gets this far; the transport answers everyone else.
		if ( ! $agent instanceof Agent || ! $agent->is_active() || $user_id < 1 ) {
			return $result;
		}

		$session_id = (string) $request->get_header( 'mcp-session-id' );

		// No session named: `initialize`, or a call the adapter refuses with 400 as the specification allows.
		if ( '' === $session_id ) {
			return $result;
		}

		$body   = $request->get_json_params();
		$method = is_array( $body ) ? (string) ( $body['method'] ?? '' ) : '';

		// `initialize` starts a session; the session it names, if any, is not consulted.
		if ( 'initialize' === $method ) {
			return $result;
		}

		$session = \WP\MCP\Transport\Infrastructure\SessionManager::get_session( $user_id, $session_id );

		if ( is_array( $session ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => is_array( $body ) && array_key_exists( 'id', $body ) ? $body['id'] : null,
				'error'   => array(
					'code'    => self::ERROR_CODE,
					'message' => 'Session not found. Start a new session with initialize.',
				),
			),
			404
		);
	}
}
