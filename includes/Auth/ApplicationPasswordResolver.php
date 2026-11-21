<?php
/**
 * Application password agent resolution.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the agent from a WordPress application password.
 *
 * The fallback for clients that cannot send a custom Authorization header and
 * only speak HTTP Basic. WordPress authenticates the application password
 * itself and sets the current user; all we do is recognise that the resulting
 * user is the agent's shadow user and bind the agent.
 *
 * This resolver therefore runs *after* core authentication rather than instead
 * of it, and it never validates a credential of its own.
 */
final class ApplicationPasswordResolver implements AgentResolverInterface {

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agent persistence.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * Whether an application password could be in play.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function supports( ?\WP_REST_Request $request = null ): bool {
		unset( $request );

		return get_current_user_id() > 0;
	}

	/**
	 * Bind the agent behind the currently authenticated shadow user.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function resolve( ?\WP_REST_Request $request = null ): ?Agent {
		unset( $request );

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return null;
		}

		// Identification only. An inactive agent is still returned so its
		// refusal is attributed to it; TransportPermission decides and logs.
		$agent = $this->agents->get_by_shadow_user( $user_id );

		return $agent instanceof Agent ? $agent : null;
	}

	/**
	 * The auth method this resolver represents.
	 */
	public function get_auth_method(): string {
		return 'app_password';
	}
}
