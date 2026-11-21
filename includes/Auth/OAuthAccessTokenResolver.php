<?php
/**
 * OAuth access token agent resolution.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Governance\Decision;
use AiAgentOs\Mcp\Routes;
use AiAgentOs\OAuth\OAuthEndpoints;
use AiAgentOs\OAuth\OAuthStore;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the agent from an access token issued by the connect flow.
 *
 * The same contract as BearerTokenResolver: identification only. A token that
 * is unknown, expired or spent resolves to nobody; a valid token resolves to
 * its agent even when that agent is revoked, so the refusal downstream is
 * logged against the agent that was actually presented.
 *
 * Expired is "nobody" rather than "the agent, inactive", because an expired
 * access token is routine — the client is expected to refresh — and the 401
 * with `error="invalid_token"` is what tells it to.
 */
final class OAuthAccessTokenResolver implements AgentResolverInterface {

	/**
	 * Credential persistence.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Header parsing shared with agent tokens.
	 *
	 * @var BearerTokenResolver
	 */
	private BearerTokenResolver $bearer;

	/**
	 * Constructor.
	 *
	 * @param OAuthStore          $store  Credential persistence.
	 * @param AgentRepository     $agents Agent persistence.
	 * @param BearerTokenResolver $bearer Header parsing shared with agent tokens.
	 */
	public function __construct( OAuthStore $store, AgentRepository $agents, BearerTokenResolver $bearer ) {
		$this->store  = $store;
		$this->agents = $agents;
		$this->bearer = $bearer;
	}

	/**
	 * Whether a bearer token shaped like one of our access tokens is present.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function supports( ?\WP_REST_Request $request = null ): bool {
		$token = $this->bearer->presented_token( $request );

		return null !== $token && $this->store->looks_like_access_token( $token );
	}

	/**
	 * Resolve the access token to the agent it acts as.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function resolve( ?\WP_REST_Request $request = null ): ?Agent {
		$token = $this->bearer->presented_token( $request );

		if ( null === $token ) {
			return null;
		}

		$row = $this->store->find( $token, OAuthStore::TYPE_ACCESS );

		/*
		 * Each refusal says why (AgentContext::set_credential_problem()), so the
		 * transport can report the real cause. Unknown, spent and expired
		 * tokens are routine or forgeable and name nobody; a token issued for
		 * another address is neither, so it names its agent.
		 */
		if ( null === $row || null !== $row['consumed_at'] ) {
			AgentContext::set_credential_problem( Decision::INVALID_TOKEN );

			return null;
		}

		$expires = strtotime( (string) $row['expires_at'] . ' UTC' );

		if ( false === $expires || time() >= $expires ) {
			AgentContext::set_credential_problem( Decision::TOKEN_EXPIRED );

			return null;
		}

		// A token works only at the address it was issued for.
		if ( ! self::issued_for_this_address( (string) ( $row['resource'] ?? '' ) ) ) {
			AgentContext::set_credential_problem( Decision::TOKEN_ADDRESS_MISMATCH, (int) $row['agent_id'] );

			return null;
		}

		// Stored against the agent's record: a credential left from an earlier setup of the plugin does not carry over.
		$agent = $this->agents->get();
		$agent = $agent instanceof Agent && (int) $row['agent_id'] === $agent->get_id() ? $agent : null;

		if ( ! $agent instanceof Agent ) {
			AgentContext::set_credential_problem( Decision::INVALID_TOKEN );

			return null;
		}

		if ( $agent->is_active() ) {
			$this->agents->touch_token_used();
		} else {
			/*
			 * Expiry passes without anything being written, so nothing told the
			 * store. The first use after it does: the call is refused for the
			 * agent's reason, and the connection must be approved again.
			 */
			$this->store->delete_for_agent( $agent->get_id() );
		}

		return $agent;
	}

	/**
	 * Whether a token's resource is the MCP address this request is for.
	 *
	 * A token works only at the MCP address it was approved for, so it stops
	 * working when that address is replaced. One issued without a recorded
	 * address is refused.
	 *
	 * @param string $issued_for The token's resource.
	 */
	private static function issued_for_this_address( string $issued_for ): bool {
		$code = Routes::requested_agent_code();

		// Only the MCP address is served, and a sign-in works only at the address it was approved for.
		if ( '' === $code || '' === $issued_for ) {
			return false;
		}

		$expected = strtolower( untrailingslashit( OAuthEndpoints::resource( $code ) ) );

		return hash_equals( $expected, strtolower( untrailingslashit( $issued_for ) ) );
	}

	/**
	 * The auth method this resolver represents.
	 */
	public function get_auth_method(): string {
		return 'oauth';
	}
}
