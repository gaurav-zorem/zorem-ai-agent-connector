<?php
/**
 * The site agent's lifecycle.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

defined( 'ABSPATH' ) || exit;

/**
 * Provisions the site's agent, and issues, replaces and withdraws its credentials.
 *
 * Provisioning touches the agents table and a WordPress user, so it lives here
 * rather than in a controller: the sequence and its unwinding need to be in one
 * place. It runs once, from SiteAgent, on the first admin page after
 * activation.
 */
final class AgentFactory {

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Shadow user lifecycle.
	 *
	 * @var ShadowUserManager
	 */
	private ShadowUserManager $shadow_users;

	/**
	 * Token issuance.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository   $agents       Agent persistence.
	 * @param ShadowUserManager $shadow_users Shadow user lifecycle.
	 * @param TokenService      $tokens       Token issuance.
	 */
	public function __construct(
		AgentRepository $agents,
		ShadowUserManager $shadow_users,
		TokenService $tokens
	) {
		$this->agents       = $agents;
		$this->shadow_users = $shadow_users;
		$this->tokens       = $tokens;
	}

	/**
	 * Provision the site's agent.
	 *
	 * Called once, by `SiteAgent::maybe_provision()`, the first time an
	 * administrator opens the admin after activation. Nothing else in the
	 * plugin calls it: there is no route, no screen and no setting that makes
	 * an agent, and `AgentRepository` reads the one the site has rather than a
	 * collection. One agent is what this plugin is, not a limit placed on it,
	 * and a second call is refused below whoever makes it.
	 *
	 * @param array<string, mixed> $args {
	 *     The agent to set up.
	 *
	 *     @type string $name                Required. Display name.
	 *     @type string $description         Free-text description.
	 *     @type string $acts_for            admin, public or partner. Default admin.
	 *     @type bool   $pii_masked          Whether to mask customer personal data.
	 *     @type bool   $destructive_enabled Whether destructive abilities may run.
	 *     @type bool   $issue_token         Whether to issue a bearer token. Default true.
	 * }
	 * @return array<string, mixed>|\WP_Error On success: agent, token. The
	 *                                       plaintext token is present exactly once, here.
	 */
	public function provision( array $args ) {
		$name = trim( (string) ( $args['name'] ?? '' ) );

		if ( '' === $name ) {
			return new \WP_Error(
				'ai_agent_os_agent_missing_name',
				__( 'An agent must have a name.', 'zorem-ai-agent-connector' )
			);
		}

		/*
		 * Refused here, not only in the one caller.
		 *
		 * `SiteAgent` already provisions once and marks an option so it never
		 * runs again, but that makes "one agent" a property of a caller rather
		 * than of the plugin: anything holding this object could make a second
		 * one, and the rest of the code — `AgentRepository::current()`, the
		 * single-agent screen, the consent screen — is written as though a
		 * second cannot exist. Checked against the database rather than that
		 * option, so a site whose option was lost or edited still gets one
		 * agent rather than a duplicate.
		 *
		 * This is the plugin's shape, not a quota: there is nothing to raise
		 * it to, and nothing is withheld behind it.
		 */
		$this->agents->flush_cache();

		if ( $this->agents->current() instanceof Agent ) {
			return new \WP_Error(
				'ai_agent_os_agent_exists',
				__( 'This site already has its agent. Change what it may do on its Permissions tab.', 'zorem-ai-agent-connector' )
			);
		}

		$uuid = wp_generate_uuid4();

		$shadow_user_id = $this->shadow_users->create( $uuid, $name );

		if ( is_wp_error( $shadow_user_id ) ) {
			return $shadow_user_id;
		}

		/*
		 * The agent may be set up without a credential.
		 *
		 * A token is shown exactly once, when it is issued, and stored only as
		 * a hash. That is the right contract for a token somebody asked for —
		 * and the wrong one for an agent the plugin sets up on the merchant's
		 * behalf, where "shown once" would mean shown to nobody. A live
		 * credential no human has ever seen is not a credential, it is clutter
		 * with a security shape.
		 *
		 * So the agent starts tokenless: its permissions are visible and
		 * reviewable, and nothing can authenticate as it until an administrator
		 * issues a token deliberately. Authentication matches a token hash, and
		 * there is nothing to match. Signing in from Claude or ChatGPT needs no
		 * token at all.
		 */
		$issue_token = ! array_key_exists( 'issue_token', $args ) || (bool) $args['issue_token'];

		/*
		 * Null, not an empty string: `token_hash` carries a UNIQUE index, and
		 * MySQL exempts NULL from uniqueness. The keys are the ones
		 * `TokenService::generate()` returns, so the placeholder has the real
		 * value's shape rather than agreeing with it on two keys out of three.
		 */
		$token = array(
			'token'  => '',
			'hash'   => null,
			'prefix' => null,
		);

		if ( $issue_token ) {
			try {
				$token = $this->tokens->generate();
			} catch ( \RuntimeException $e ) {
				$this->shadow_users->delete( (int) $shadow_user_id );

				return new \WP_Error( 'ai_agent_os_token_generation_failed', $e->getMessage() );
			}
		}

		$acts_for = Agent::normalize_acts_for( (string) ( $args['acts_for'] ?? '' ), Agent::ACTS_FOR_ADMIN );

		$slug = sanitize_title( $name );

		$agent_id = $this->agents->insert(
			array(
				'uuid'             => $uuid,
				'name'             => $name,
				'slug'             => '' === $slug ? 'agent' : $slug,
				'acts_for'         => $acts_for,
				'status'           => Agent::STATUS_ACTIVE,
				'auth_method'      => 'bearer',
				'token_hash'       => $token['hash'],
				'token_prefix'     => $token['prefix'],
				// Null rather than "now" when nothing was issued, so the age of a
				// token is never reported for an agent that has none.
				'token_created_at' => $issue_token ? current_time( 'mysql', true ) : null,
				'shadow_user_id'   => (int) $shadow_user_id,
				'meta'             => array(
					'description'         => (string) ( $args['description'] ?? '' ),
					'destructive_enabled' => (bool) ( $args['destructive_enabled'] ?? false ),
					'pii_masked'          => $this->resolve_pii_setting( $args, $acts_for ),
				),
			)
		);

		if ( is_wp_error( $agent_id ) ) {
			// Do not leave an orphaned user behind when the row failed.
			$this->shadow_users->delete( (int) $shadow_user_id );

			return $agent_id;
		}

		$agent = $this->agents->find( (int) $agent_id );

		if ( ! $agent instanceof Agent ) {
			return new \WP_Error(
				'ai_agent_os_agent_not_found',
				__( 'The agent was set up but could not be read back.', 'zorem-ai-agent-connector' )
			);
		}

		/**
		 * Fires after the site's agent is provisioned.
		 *
		 * @param Agent $agent The agent.
		 */
		do_action( 'ai_agent_os_agent_created', $agent );

		return array(
			'agent' => $agent,
			'token' => $token['token'],
		);
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
			$agent->get_id(),
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
		 * Fires after an agent's token is rotated.
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
		$revoked = $this->agents->update( $agent->get_id(), array( 'status' => Agent::STATUS_REVOKED ) );

		if ( $revoked ) {
			/**
			 * Fires after an agent is revoked.
			 *
			 * @param Agent $agent The agent.
			 */
			do_action( 'ai_agent_os_agent_revoked', $agent );
		}

		return $revoked;
	}

	/**
	 * Reactivate a revoked agent.
	 *
	 * @param Agent $agent The agent.
	 */
	public function reactivate( Agent $agent ): bool {
		return $this->agents->update( $agent->get_id(), array( 'status' => Agent::STATUS_ACTIVE ) );
	}

	/**
	 * Decide the agent's PII masking setting.
	 *
	 * An explicit argument beats the default. The default masks for any agent
	 * that does not act for the store administrator: the same rule as
	 * `Agent::masks_pii()`, which this value is stored over.
	 *
	 * @param array<string, mixed> $args     Provisioning args.
	 * @param string               $acts_for Who the agent acts for, already resolved.
	 */
	private function resolve_pii_setting( array $args, string $acts_for ): bool {
		if ( array_key_exists( 'pii_masked', $args ) ) {
			return (bool) $args['pii_masked'];
		}

		return Agent::ACTS_FOR_ADMIN !== $acts_for;
	}
}
