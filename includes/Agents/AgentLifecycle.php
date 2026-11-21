<?php
/**
 * The site agent's credentials and status.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Issues, replaces and withdraws the agent's token, and revokes or
 * reactivates the agent.
 *
 * The agent itself is created by SiteAgent when the plugin is set up.
 */
final class AgentLifecycle {

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Token issuance.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agent persistence.
	 * @param TokenService    $tokens Token issuance.
	 */
	public function __construct( AgentRepository $agents, TokenService $tokens ) {
		$this->agents = $agents;
		$this->tokens = $tokens;
	}

	/**
	 * Issue a new token, invalidating the old one immediately.
	 *
	 * @param Agent $agent The agent.
	 * @return string|\WP_Error The new plaintext token, returned once.
	 */
	public function rotate_token( Agent $agent ) {
		try {
			$token = $this->tokens->generate();
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ai_agent_os_token_generation_failed', $e->getMessage() );
		}

		$updated = $this->agents->update(
			array(
				'token_hash'         => $token['hash'],
				'token_prefix'       => $token['prefix'],
				'token_created_at'   => current_time( 'mysql', true ),
				'token_last_used_at' => null,
			)
		);

		if ( ! $updated ) {
			return new \WP_Error(
				'ai_agent_os_token_rotation_failed',
				__( 'The token could not be replaced.', 'zorem-ai-agent-connector' )
			);
		}

		/**
		 * Fires after the agent's token is rotated.
		 *
		 * @param Agent $agent The agent.
		 */
		do_action( 'ai_agent_os_agent_token_rotated', $agent );

		return $token['token'];
	}

	/**
	 * Revoke the agent.
	 *
	 * Status only. The next request fails at the transport gate, so revocation
	 * takes effect immediately without needing to invalidate anything cached.
	 * Its permissions are kept, so it can be reactivated.
	 *
	 * @param Agent $agent The agent.
	 */
	public function revoke( Agent $agent ): bool {
		$revoked = $this->agents->update( array( 'status' => Agent::STATUS_REVOKED ) );

		if ( $revoked ) {
			/**
			 * Fires after the agent is revoked.
			 *
			 * @param Agent $agent The agent.
			 */
			do_action( 'ai_agent_os_agent_revoked', $agent );
		}

		return $revoked;
	}

	/**
	 * Reactivate the agent after it was revoked.
	 *
	 * The agent is named for symmetry with `revoke()` and so a caller states
	 * which record it means; the repository addresses the site's one agent, so
	 * it is not needed to find the row.
	 *
	 * @param Agent $agent The agent.
	 */
	public function reactivate( Agent $agent ): bool {
		unset( $agent );

		return $this->agents->update( array( 'status' => Agent::STATUS_ACTIVE ) );
	}
}
