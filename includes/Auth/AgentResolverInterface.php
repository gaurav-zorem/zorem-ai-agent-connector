<?php
/**
 * Agent resolution contract.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use AiAgentOs\Agents\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Turns an inbound request into an agent identity.
 *
 * This is the seam that keeps the governance core independent of how a caller
 * proves who it is: bearer tokens, connect-flow access tokens and an
 * application-password fallback, none of which needed a change to
 * PermissionResolver, AbilityGate or the audit log.
 *
 * Implementations must not grant anything. Their only job is identification:
 * whether the agent may then act is decided downstream, every time.
 */
interface AgentResolverInterface {

	/**
	 * Whether this resolver recognises the shape of the request's credentials.
	 *
	 * Cheap checks only — a header's presence, a query parameter's name. Never
	 * a database lookup.
	 *
	 * @param \WP_REST_Request|null $request Current request, when available.
	 */
	public function supports( ?\WP_REST_Request $request = null ): bool;

	/**
	 * Resolve the request to an agent.
	 *
	 * Returns null when the credentials are absent, malformed, unknown,
	 * expired or revoked. Callers must treat null as "no agent", never as
	 * "some default agent".
	 *
	 * @param \WP_REST_Request|null $request Current request, when available.
	 */
	public function resolve( ?\WP_REST_Request $request = null ): ?Agent;

	/**
	 * The auth_method value this resolver corresponds to.
	 */
	public function get_auth_method(): string;
}
