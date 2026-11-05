<?php
/**
 * MCP transport permission callback.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Audit\ActivityRecorder;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Governance\Decision;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a request may reach the MCP endpoint at all.
 *
 * Returns a strict boolean, always, and this is not incidental.
 *
 * The adapter's HttpTransport::check_permission() treats a WP_Error return
 * from a transport permission callback as a soft failure: it logs the error
 * and then falls through to its default check, which is
 * current_user_can( 'read' ). A WP_Error returned from here would therefore
 * hand the endpoint to any logged-in subscriber. Returning false is the only
 * safe contract, so nothing in this class may ever return a WP_Error, and the
 * test suite asserts it.
 */
final class TransportPermission {

	/**
	 * Audit trail.
	 *
	 * @var ActivityRecorder
	 */
	private ActivityRecorder $activity;

	/**
	 * Whether this request's transport refusal has already been recorded.
	 *
	 * WordPress calls a route's permission callback more than once per request:
	 * once for the real dispatch, and again from rest_send_allow_header() for
	 * every HTTP method the route declares, so it can advertise the permitted
	 * ones in the Allow header. The MCP route declares POST, GET and DELETE,
	 * which means a single refused request would otherwise write four identical
	 * blocked rows — inflating the audit trail and handing anyone hammering the
	 * endpoint a four-times write amplification, on the synchronous path.
	 *
	 * A refusal is a property of the request, not of each method probed, so it
	 * is recorded once. Keyed to the request id rather than a plain flag, so
	 * the guard is scoped to what it actually describes and cannot leak across
	 * requests that share a process.
	 *
	 * @var string|null
	 */
	private ?string $refusal_logged_for = null;

	/**
	 * Most rows a minute for refusals with no agent behind them.
	 */
	private const ANONYMOUS_PER_MINUTE = 30;

	/**
	 * Constructor.
	 *
	 * @param ActivityRecorder $activity Audit trail.
	 */
	public function __construct( ActivityRecorder $activity ) {
		$this->activity = $activity;
	}

	/**
	 * Whether the request may proceed.
	 *
	 * @param \WP_REST_Request|null $request The request.
	 * @return bool Never a WP_Error. See the class docblock.
	 */
	public function check( $request = null ): bool {
		unset( $request );

		$agent = AgentContext::get();

		if ( null === $agent ) {
			/*
			 * No agent resolved: either no credentials were presented, or the
			 * ones presented were rejected. Either way this is a refusal, and
			 * it is logged so an admin can see failed connection attempts
			 * rather than only successful ones.
			 */
			$this->record_refusal( null, 'invalid_token' );

			return false;
		}

		if ( ! $agent->is_active() ) {
			$this->record_refusal( $agent, (string) ( $agent->inactive_reason() ?? Decision::AGENT_REVOKED ) );

			return false;
		}

		return true;
	}

	/**
	 * An agent's own address: the same checks, and only for the agent it belongs to.
	 *
	 * This is the isolation between agents' addresses. A token (or a Claude
	 * sign-in) still decides who is calling; at an agent's own address only
	 * that agent may call. Another agent's valid token is refused and logged
	 * as `wrong_agent_address`, so a connector for one agent can never act as
	 * another, even with a credential copied from it.
	 *
	 * @param mixed $request The REST request.
	 */
	public function check_agent( $request = null ): bool {
		if ( ! $this->check( $request ) ) {
			return false;
		}

		$agent = AgentContext::get();
		$code  = Routes::requested_agent_code();
		$own   = $agent instanceof Agent ? (string) $agent->meta( AgentAddress::META, '' ) : '';

		if ( '' === $code || ! AgentAddress::is_code( $own ) || ! hash_equals( $own, $code ) ) {
			$this->record_refusal( $agent, Decision::WRONG_ADDRESS );

			return false;
		}

		return true;
	}

	/**
	 * Record a transport refusal, at most once per request.
	 *
	 * @param Agent|null $agent  The agent, when one was identified.
	 * @param string     $reason Block reason code.
	 */
	private function record_refusal( ?Agent $agent, string $reason ): void {
		$request_id = AgentContext::request_id();

		if ( $this->refusal_logged_for === $request_id ) {
			return;
		}

		$this->refusal_logged_for = $request_id;

		if ( null === $agent && ! self::anonymous_row_allowed() ) {
			return;
		}

		$this->activity->blocked( $agent, '', $reason, null, array( 'stage' => 'transport' ) );
	}

	/**
	 * Whether a refusal with no agent behind it may write a row.
	 *
	 * Anyone can send a bad token, as fast as they like. One row per address
	 * per minute, and at most ANONYMOUS_PER_MINUTE rows a minute for the site
	 * however many addresses there are, keeps the log about agents rather
	 * than about whoever is knocking.
	 */
	private static function anonymous_row_allowed(): bool {
		$minute  = (int) floor( time() / MINUTE_IN_SECONDS );
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$mine    = 'aaos_anon_' . md5( $address . '|' . $minute );
		$site    = 'aaos_anon_site_' . $minute;
		$count   = (int) get_transient( $site );

		if ( false !== get_transient( $mine ) || $count >= self::ANONYMOUS_PER_MINUTE ) {
			return false;
		}

		set_transient( $mine, 1, 2 * MINUTE_IN_SECONDS );
		set_transient( $site, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
