<?php
/**
 * Bearer token agent resolution.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Agents\TokenService;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an agent from an Authorization: Bearer header.
 *
 * The primary Phase 1 credential. MCP clients such as Claude Desktop send a
 * custom Authorization header, so this covers the main path.
 */
final class BearerTokenResolver implements AgentResolverInterface {

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Token hashing and comparison.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agent persistence.
	 * @param TokenService    $tokens Token hashing and comparison.
	 */
	public function __construct( AgentRepository $agents, TokenService $tokens ) {
		$this->agents = $agents;
		$this->tokens = $tokens;
	}

	/**
	 * Whether a bearer token that looks like ours is present.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function supports( ?\WP_REST_Request $request = null ): bool {
		$token = $this->extract_token( $request );

		return null !== $token && $this->tokens->looks_like_token( $token );
	}

	/**
	 * Resolve the bearer token to the agent that owns it.
	 *
	 * Identification only, as AgentResolverInterface specifies: a missing
	 * header, a malformed token, an unknown hash or a failed comparison all
	 * return null, but a *valid* token resolves to its agent even when that
	 * agent is revoked, suspended or expired.
	 *
	 * That distinction is the point. Returning null for a revoked agent would
	 * make its rejected requests indistinguishable from an attacker guessing
	 * tokens — both would log as invalid_token — and "you see what agents
	 * tried" stops being true precisely when it matters. Whether the agent may
	 * act is decided downstream by TransportPermission and PermissionResolver,
	 * which both default to deny and log the specific reason.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function resolve( ?\WP_REST_Request $request = null ): ?Agent {
		$token = $this->extract_token( $request );

		if ( null === $token || ! $this->tokens->looks_like_token( $token ) ) {
			return null;
		}

		$agent = $this->agents->find_by_token_hash( $this->tokens->hash( $token ) );

		if ( ! $agent instanceof Agent ) {
			return null;
		}

		/*
		 * The lookup above already matched on the hash, so this cannot fail in
		 * practice. It is kept because the guarantee we want is "a token is
		 * only ever accepted after a constant-time comparison", and that should
		 * hold regardless of how the row was found.
		 */
		$stored = $this->stored_hash( $agent );

		if ( null === $stored || ! $this->tokens->verify( $token, $stored ) ) {
			return null;
		}

		// "Last used" records accepted credentials, not refused attempts.
		if ( $agent->is_active() ) {
			$this->agents->touch_token_used( $agent->get_id() );
		}

		return $agent;
	}

	/**
	 * The auth method this resolver represents.
	 */
	public function get_auth_method(): string {
		return 'bearer';
	}

	/**
	 * The token in the request's Authorization header, whatever its kind.
	 *
	 * Shared with OAuthAccessTokenResolver so both credential types are read
	 * from the header by one parser, and neither has a query-string fallback.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	public function presented_token( ?\WP_REST_Request $request = null ): ?string {
		return $this->extract_token( $request );
	}

	/**
	 * Read the stored hash for an agent.
	 *
	 * Agent deliberately does not expose its token hash — nothing outside
	 * authentication should be able to reach it — so it is read directly here.
	 *
	 * @param Agent $agent The agent.
	 */
	private function stored_hash( Agent $agent ): ?string {
		global $wpdb;

		$table = \AiAgentOs\Core\Installer::table( \AiAgentOs\Core\Installer::TABLE_AGENTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$hash = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT token_hash FROM %i WHERE id = %d', $table, $agent->get_id() )
		);

		return is_string( $hash ) && '' !== $hash ? $hash : null;
	}

	/**
	 * Pull the bearer token out of the request.
	 *
	 * Checks the REST request first, then the raw server superglobals, because
	 * this resolver also runs on determine_current_user — before WordPress has
	 * built a WP_REST_Request object.
	 *
	 * @param \WP_REST_Request|null $request Current request.
	 */
	private function extract_token( ?\WP_REST_Request $request = null ): ?string {
		$header = null;

		if ( $request instanceof \WP_REST_Request ) {
			$header = $request->get_header( 'authorization' );
		}

		if ( null === $header || '' === $header ) {
			$header = $this->raw_authorization_header();
		}

		if ( ! is_string( $header ) || '' === $header ) {
			return null;
		}

		if ( ! preg_match( '/^Bearer\s+(\S+)$/i', trim( $header ), $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Read the Authorization header from the server environment.
	 *
	 * Some Apache configurations strip Authorization unless it is explicitly
	 * passed through, which is why the redirect variant is checked too.
	 */
	private function raw_authorization_header(): ?string {
		$candidates = array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' );

		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
			}
		}

		if ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();

			if ( is_array( $headers ) ) {
				foreach ( $headers as $name => $value ) {
					if ( 'authorization' === strtolower( (string) $name ) ) {
						return sanitize_text_field( (string) $value );
					}
				}
			}
		}

		return null;
	}
}
