<?php
/**
 * Refuses JSON-RPC batches on the MCP address.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Audit\ActivityRecorder;
use AiAgentOs\Auth\AgentContext;

defined( 'ABSPATH' ) || exit;

/**
 * Answers a JSON-RPC batch on the MCP address with HTTP 400.
 *
 * MCP no longer uses batches, and a batch carries several calls in one body,
 * where everything that reads the request (the refusal reports, the
 * `user_request` field) sees only the top level. So batches are refused
 * outright, by the bundled MCP Adapter's own rule: it runs a body as a batch
 * whenever the body has a key 0 (`JsonRpcResponseBuilder::is_batch_request`),
 * so `{"0":{…},"2":{…}}` counts as one too, not just a JSON list.
 */
final class BatchRefusal {

	/**
	 * Where refusals are reported.
	 *
	 * @var ActivityRecorder
	 */
	private ActivityRecorder $activity;

	/**
	 * Constructor.
	 *
	 * @param ActivityRecorder $activity Where refusals are reported.
	 */
	public function __construct( ActivityRecorder $activity ) {
		$this->activity = $activity;
	}

	/**
	 * Hook the check.
	 */
	public function register(): void {
		// Priority 1: after AgentAuthenticator's shadow-user refusal (0), before anything reads the body.
		add_filter( 'rest_pre_dispatch', array( $this, 'check' ), 1, 3 );
	}

	/**
	 * Refuse a batch-shaped body on the MCP route.
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

		if ( ! Routes::is_mcp_route( $request->get_route() ) ) {
			return $result;
		}

		$body = $request->get_json_params();

		if ( ! is_array( $body ) || ! array_key_exists( 0, $body ) ) {
			return $result;
		}

		$agent = AgentContext::get();

		$this->activity->blocked( $agent instanceof Agent ? $agent : null, '', 'jsonrpc_batch', null, array( 'stage' => 'transport' ) );

		return new \WP_Error(
			'ai_agent_os_batch_refused',
			__( 'Send one JSON-RPC request at a time: batches are not accepted on this MCP address.', 'zorem-ai-agent-connector' ),
			array( 'status' => 400 )
		);
	}
}
