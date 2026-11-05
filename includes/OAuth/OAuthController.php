<?php
/**
 * The connect flow's REST routes.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Core\Settings;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery copies, authorize, token and (optionally) registration.
 *
 * Every route here is public: a client has no credential until this
 * controller gives it one. Each therefore decides for itself what it will
 * accept, and the token endpoint in particular checks everything a code was
 * bound to — client, redirect URI, PKCE verifier and resource — before it
 * spends the code.
 *
 * Errors use the OAuth shape (`error`, `error_description`), not the WP_Error
 * shape, because the reader is an OAuth client.
 */
final class OAuthController {

	/**
	 * Seconds after a refresh token is rotated during which presenting it
	 * again counts as the client's own concurrent refresh, not a replay.
	 *
	 * Long enough for parallel requests and a retry after a slow response;
	 * short enough that a token lifted from a log is useless by the time
	 * anyone tries it.
	 */
	public const REFRESH_REUSE_GRACE = 30;

	/**
	 * Discovery documents.
	 *
	 * @var OAuthMetadata
	 */
	private OAuthMetadata $metadata;

	/**
	 * Credential persistence.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Client identification.
	 *
	 * @var ClientResolver
	 */
	private ClientResolver $clients;

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param OAuthMetadata   $metadata Discovery documents.
	 * @param OAuthStore      $store    Credential persistence.
	 * @param ClientResolver  $clients  Client identification.
	 * @param AgentRepository $agents   Agent persistence.
	 * @param Settings        $settings Plugin settings.
	 */
	public function __construct( OAuthMetadata $metadata, OAuthStore $store, ClientResolver $clients, AgentRepository $agents, Settings $settings ) {
		$this->metadata = $metadata;
		$this->store    = $store;
		$this->clients  = $clients;
		$this->agents   = $agents;
		$this->settings = $settings;
	}

	/**
	 * Hook route registration.
	 */
	public function register(): void {
		if ( ! $this->settings->bool( 'mcp_enabled' ) ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		$base = '/' . OAuthEndpoints::BASE;

		register_rest_route(
			Routes::NAMESPACE,
			$base . '/protected-resource/(?P<code>' . \AiAgentOs\Mcp\AgentAddress::PATTERN . ')',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'protected_resource' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Routes::NAMESPACE,
			$base . '/\.well-known/(?:oauth-authorization-server|openid-configuration)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'authorization_server' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Routes::NAMESPACE,
			$base . '/authorize',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'authorize' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Routes::NAMESPACE,
			$base . '/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Protected resource metadata for an agent's own address.
	 *
	 * @param \WP_REST_Request|null $request Request; `code` names an agent's address.
	 */
	public function protected_resource( $request = null ): \WP_REST_Response {
		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		$code = $request instanceof \WP_REST_Request ? (string) $request->get_param( 'code' ) : '';

		if ( ! \AiAgentOs\Mcp\AgentAddress::is_code( $code ) ) {
			return $this->oauth_error( 'invalid_target', 'Each agent has its own MCP address; there is no shared one.', 404 );
		}

		return $this->json( $this->metadata->protected_resource( $code ) );
	}

	/**
	 * Authorization server metadata, at the issuer-relative locations.
	 */
	public function authorization_server(): \WP_REST_Response {
		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		return $this->json( $this->metadata->authorization_server() );
	}

	/**
	 * Send the browser to the consent screen.
	 *
	 * Nothing is validated or stored here. The consent screen validates the
	 * same parameters itself, behind a wp-admin login — doing it here as well
	 * would be a second copy of the rules that could drift.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function authorize( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		$params = array();

		foreach ( AuthorizationRequest::PARAMS as $key ) {
			$value = $request->get_query_params()[ $key ] ?? null;

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$params[ $key ] = rawurlencode( (string) $value );
			}
		}

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', add_query_arg( $params, OAuthEndpoints::consent_url() ) );

		return $response;
	}

	/**
	 * The token endpoint: code exchange and refresh.
	 *
	 * Parameters are read from the body only. A code or refresh token in a
	 * query string is a credential in a log line (S13).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function token( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		// The site's address being https says nothing about this request: credentials travel only over TLS.
		if ( ! is_ssl() ) {
			return $this->oauth_error( 'invalid_request', 'Use https for this endpoint.', 400 );
		}

		$body  = $request->get_body_params();
		$body  = array() !== $body ? $body : (array) $request->get_json_params();
		$grant = (string) ( $body['grant_type'] ?? '' );

		if ( 'authorization_code' === $grant ) {
			return $this->exchange_code( $body );
		}

		if ( 'refresh_token' === $grant ) {
			return $this->refresh( $body );
		}

		return $this->oauth_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
	}

	/**
	 * Exchange an authorization code for tokens.
	 *
	 * @param array<string, mixed> $body Body parameters.
	 */
	private function exchange_code( array $body ): \WP_REST_Response {
		$row = $this->store->find( (string) ( $body['code'] ?? '' ), OAuthStore::TYPE_CODE );

		if ( null === $row ) {
			return $this->oauth_error( 'invalid_grant', 'Unknown authorization code.' );
		}

		/*
		 * A spent code presented again means somebody other than the client
		 * may hold it. Everything issued from that approval is burned, not
		 * only the replay refused.
		 */
		if ( null !== $row['consumed_at'] ) {
			$this->store->delete_grant( (string) $row['grant_id'] );

			return $this->oauth_error( 'invalid_grant', 'This authorization code has already been used.' );
		}

		if ( $this->is_expired( $row ) ) {
			return $this->oauth_error( 'invalid_grant', 'This authorization code has expired.' );
		}

		$client = $this->authenticate_client( $body );

		if ( $client instanceof \WP_REST_Response ) {
			return $client;
		}

		if ( $client !== $row['client_id'] ) {
			return $this->oauth_error( 'invalid_grant', 'This code was issued to a different client.' );
		}

		if ( (string) ( $body['redirect_uri'] ?? '' ) !== (string) $row['redirect_uri'] ) {
			return $this->oauth_error( 'invalid_grant', 'redirect_uri does not match the authorization request.' );
		}

		if ( ! $this->verify_pkce( (string) ( $body['code_verifier'] ?? '' ), (string) $row['code_challenge'] ) ) {
			return $this->oauth_error( 'invalid_grant', 'The PKCE code_verifier does not match.' );
		}

		$resource = (string) ( $body['resource'] ?? '' );

		if ( '' !== $resource && ! AuthorizationRequest::is_our_resource( $resource ) ) {
			return $this->oauth_error( 'invalid_target', 'This server only issues tokens for its own MCP endpoint.' );
		}

		/*
		 * RFC 8707: the token is for the address that was approved. Signed in
		 * at one agent's address, a client cannot exchange the code for another.
		 */
		if ( '' !== $resource && '' !== (string) $row['resource'] && strtolower( untrailingslashit( $resource ) ) !== strtolower( untrailingslashit( (string) $row['resource'] ) ) ) {
			return $this->oauth_error( 'invalid_target', 'This code was approved for a different MCP address.' );
		}

		if ( ! $this->store->consume( (int) $row['id'] ) ) {
			return $this->oauth_error( 'invalid_grant', 'This authorization code has already been used.' );
		}

		$agent = $this->agents->find( (int) $row['agent_id'] );

		if ( ! $agent instanceof Agent ) {
			return $this->oauth_error( 'invalid_grant', 'The agent this code was approved for no longer exists.' );
		}

		// The admin connected an existing agent; one that is not active (now) gets nothing.
		if ( ! $agent->is_active() ) {
			$this->store->delete_grant( (string) $row['grant_id'] );

			return $this->oauth_error( 'invalid_grant', 'The agent this code was approved for is not active.' );
		}

		return $this->issue_pair( $agent, (string) $row['grant_id'], $client, (string) $row['resource'] );
	}

	/**
	 * Rotate a refresh token (S10).
	 *
	 * @param array<string, mixed> $body Body parameters.
	 */
	private function refresh( array $body ): \WP_REST_Response {
		$row = $this->store->find( (string) ( $body['refresh_token'] ?? '' ), OAuthStore::TYPE_REFRESH );

		if ( null === $row ) {
			return $this->oauth_error( 'invalid_grant', 'Unknown refresh token.' );
		}

		/*
		 * A rotated-out refresh token presented again.
		 *
		 * Within a few seconds of its rotation this is the client itself:
		 * two of its requests found the access token expired at the same
		 * moment and both refreshed with the token they held. Burning the
		 * chain there disconnects a client that did nothing wrong — the likely
		 * cause of claude.ai dropping on demo.zorem.dev with two refusals one
		 * second apart — so inside the window the replay is answered with a
		 * new pair, as the first refresh was.
		 *
		 * Outside it, a spent token turning up is what theft looks like
		 * (RFC 9700 §4.14.2): the chain is burned and the client must be
		 * approved again.
		 */
		$reused = null !== $row['consumed_at'];

		if ( $reused && ! $this->within_reuse_grace( $row ) ) {
			$this->store->delete_grant( (string) $row['grant_id'] );

			return $this->oauth_error( 'invalid_grant', 'This refresh token has already been used.' );
		}

		if ( $this->is_expired( $row ) ) {
			return $this->oauth_error( 'invalid_grant', 'This refresh token has expired.' );
		}

		// An approval lasts MAX_GRANT_AGE at most, however often it is refreshed; then the client asks again.
		$granted = strtotime( (string) ( $row['grant_created_at'] ?? '' ) . ' UTC' );

		if ( false !== $granted && time() - $granted > OAuthStore::MAX_GRANT_AGE ) {
			$this->store->delete_grant( (string) $row['grant_id'] );

			return $this->oauth_error( 'invalid_grant', 'This connection was approved too long ago. Approve it again.' );
		}

		$client = $this->authenticate_client( $body );

		if ( $client instanceof \WP_REST_Response ) {
			return $client;
		}

		if ( $client !== $row['client_id'] ) {
			return $this->oauth_error( 'invalid_grant', 'This refresh token was issued to a different client.' );
		}

		$agent = $this->agents->find( (int) $row['agent_id'] );

		// D5: a revoked, expired or deleted agent's refresh tokens are dead.
		if ( ! $agent instanceof Agent || ! $agent->is_active() ) {
			$this->store->delete_grant( (string) $row['grant_id'] );

			return $this->oauth_error( 'invalid_grant', 'The agent behind this connection is no longer active.' );
		}

		/*
		 * Lost the race to a concurrent refresh of the same token: that is
		 * the same case as above, arriving a few milliseconds earlier.
		 */
		if ( ! $reused && ! $this->store->consume( (int) $row['id'] ) ) {
			$row    = $this->store->find( (string) $body['refresh_token'], OAuthStore::TYPE_REFRESH );
			$reused = true;

			if ( null === $row || ! $this->within_reuse_grace( $row ) ) {
				return $this->oauth_error( 'invalid_grant', 'This refresh token has already been used.' );
			}
		}

		/*
		 * The grace covers one replay, not any number: two requests racing
		 * is the client, a stream of pairs from one spent token is not. The
		 * replay that wins marks the token as spent long ago, so a further
		 * presentation burns the chain like any other replay.
		 */
		if ( $reused && ! $this->store->spend_grace( (int) $row['id'], (string) $row['consumed_at'] ) ) {
			return $this->oauth_error( 'invalid_grant', 'This refresh token has already been used.' );
		}

		/*
		 * The client's own retry gets the very pair the first refresh issued,
		 * not a second, independent one: one refresh, one live connection.
		 */
		if ( $reused ) {
			$remembered = $this->store->remembered_pair( (string) $body['refresh_token'] );

			if ( null !== $remembered ) {
				return $this->json( $remembered );
			}
		}

		$issued = $this->issue_pair( $agent, (string) $row['grant_id'], $client, (string) ( $row['resource'] ?? '' ), (string) ( $row['grant_created_at'] ?? '' ) );

		if ( ! $reused && 200 === $issued->get_status() ) {
			$this->store->remember_pair( (string) $body['refresh_token'], (array) $issued->get_data(), self::REFRESH_REUSE_GRACE );
		}

		return $issued;
	}

	/**
	 * Whether a spent refresh token was spent recently enough to be a
	 * concurrent refresh by its own client rather than a replay.
	 *
	 * @param array<string, mixed> $row Refresh token row.
	 */
	private function within_reuse_grace( array $row ): bool {
		if ( null === $row['consumed_at'] ) {
			return true;
		}

		$consumed = strtotime( (string) $row['consumed_at'] . ' UTC' );

		return false !== $consumed && ( time() - $consumed ) <= self::REFRESH_REUSE_GRACE;
	}

	/**
	 * Issue an access token and a refresh token.
	 *
	 * @param Agent  $agent     Agent.
	 * @param string $grant_id  Approval they descend from.
	 * @param string $client_id Client.
	 * @param string $address   The agent address the approval was for.
	 * @param string $granted   When the approval was given; now when empty.
	 */
	private function issue_pair( Agent $agent, string $grant_id, string $client_id, string $address = '', string $granted = '' ): \WP_REST_Response {
		$extra = array(
			'resource'         => $address,
			'grant_created_at' => $granted,
		);

		$access  = $this->store->issue( OAuthStore::TYPE_ACCESS, $agent->get_id(), $grant_id, $client_id, $extra );
		$refresh = $this->store->issue( OAuthStore::TYPE_REFRESH, $agent->get_id(), $grant_id, $client_id, $extra );

		if ( is_wp_error( $access ) || is_wp_error( $refresh ) ) {
			return $this->oauth_error( 'server_error', 'The tokens could not be issued.', 500 );
		}

		return $this->json(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => OAuthStore::ACCESS_TTL,
				'refresh_token' => $refresh,
			)
		);
	}

	/**
	 * Identify and, where it has a secret, authenticate the calling client.
	 *
	 * @param array<string, mixed> $body Body parameters.
	 * @return string|\WP_REST_Response The client id, or an error response.
	 */
	private function authenticate_client( array $body ) {
		$client = $this->clients->resolve( (string) ( $body['client_id'] ?? '' ) );

		if ( is_wp_error( $client ) ) {
			return $this->oauth_error( 'invalid_client', $client->get_error_message(), 401 );
		}

		// Every client is public (a metadata document with `none`): there is no secret to check.
		return (string) $client['client_id'];
	}

	/**
	 * PKCE S256 check (RFC 7636 §4.6).
	 *
	 * @param string $verifier  Presented code_verifier.
	 * @param string $challenge Stored code_challenge.
	 */
	private function verify_pkce( string $verifier, string $challenge ): bool {
		if ( '' === $challenge || ! preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url of a hash, as PKCE defines it.
		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );

		return hash_equals( $challenge, $computed );
	}

	/**
	 * Whether a credential row has expired.
	 *
	 * @param array<string, mixed> $row Row.
	 */
	private function is_expired( array $row ): bool {
		$expires = strtotime( (string) $row['expires_at'] . ' UTC' );

		return false === $expires || time() >= $expires;
	}

	/**
	 * A JSON response that no cache may keep.
	 *
	 * @param array<string, mixed> $data   Body.
	 * @param int                  $status HTTP status.
	 */
	private function json( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Access-Control-Allow-Origin', '*' );

		return $response;
	}

	/**
	 * An RFC 6749 §5.2 error response.
	 *
	 * @param string $error       Error code.
	 * @param string $description Human-readable detail.
	 * @param int    $status      HTTP status.
	 */
	private function oauth_error( string $error, string $description, int $status = 400 ): \WP_REST_Response {
		return $this->json(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}
}
