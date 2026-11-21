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
use AiAgentOs\Governance\Decision;
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
	 * Registrations allowed per address (an IPv6 /64) per hour.
	 */
	private const REGISTER_LIMIT = 10;

	/**
	 * Registrations allowed per hour from everyone together, so changing address does not help.
	 */
	private const REGISTER_LIMIT_SITE = 100;

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
	 * Refused sign-ins, on the Connect page.
	 *
	 * @var SignInRefusals
	 */
	private SignInRefusals $refusals;

	/**
	 * Constructor.
	 *
	 * @param OAuthMetadata   $metadata Discovery documents.
	 * @param OAuthStore      $store    Credential persistence.
	 * @param ClientResolver  $clients  Client identification.
	 * @param AgentRepository $agents   Agent persistence.
	 * @param Settings        $settings Plugin settings.
	 * @param SignInRefusals  $refusals Refused sign-ins, on the Connect page.
	 */
	public function __construct( OAuthMetadata $metadata, OAuthStore $store, ClientResolver $clients, AgentRepository $agents, Settings $settings, SignInRefusals $refusals ) {
		$this->metadata = $metadata;
		$this->store    = $store;
		$this->clients  = $clients;
		$this->agents   = $agents;
		$this->settings = $settings;
		$this->refusals = $refusals;
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
			$base . '/register',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_client' ),
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
	 * Protected resource metadata for the agent's MCP address.
	 *
	 * @param \WP_REST_Request|null $request Request; `code` names the agent's address.
	 */
	public function protected_resource( $request = null ): \WP_REST_Response {
		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		$code = $request instanceof \WP_REST_Request ? (string) $request->get_param( 'code' ) : '';

		if ( ! \AiAgentOs\Mcp\AgentAddress::is_code( $code ) ) {
			return $this->oauth_error( 'invalid_target', 'Sign in at the agent’s MCP address, copied from its Connect page.', 404 );
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
	 * Dynamic Client Registration (RFC 7591), for Grok only (RegistrationPolicy).
	 *
	 * Accepted only while "Let Grok sign in" is on and when every callback
	 * address is https on an allowed host. Registering grants nothing: every
	 * sign-in is still approved by an administrator on the consent screen.
	 * Each refusal is remembered for the Connect page (SignInRefusals).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function register_client( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = (array) $request->get_json_params();
		$name  = ClientResolver::clean_name( is_string( $body['client_name'] ?? null ) ? $body['client_name'] : '' );
		$uris  = $body['redirect_uris'] ?? null;
		$first = is_array( $uris ) && is_string( reset( $uris ) ) ? (string) reset( $uris ) : '';

		if ( ! OAuthEndpoints::is_available() ) {
			return $this->oauth_error( 'temporarily_unavailable', (string) OAuthEndpoints::unavailable_reason(), 404 );
		}

		if ( ! ( new RegistrationPolicy( $this->settings ) )->is_enabled() ) {
			$this->refusals->record( Decision::OAUTH_REGISTRATION_OFF, $name, $first, 'Registration is switched off on this site.' );

			return $this->oauth_error( 'invalid_request', 'Client registration is not enabled on this site.', 404 );
		}

		if ( ! is_ssl() ) {
			return $this->oauth_error( 'invalid_request', 'Use https for this endpoint.', 400 );
		}

		if ( ! $this->within_register_limit() ) {
			return $this->oauth_error( 'invalid_request', 'Too many registrations from this address. Try again later.', 429 );
		}

		if ( ! is_array( $uris ) || array() === $uris || count( $uris ) > 10 ) {
			$this->refusals->record( Decision::OAUTH_REGISTRATION_INVALID, $name, '', 'redirect_uris must list between one and ten addresses.' );

			return $this->oauth_error( 'invalid_redirect_uri', 'redirect_uris must list between one and ten addresses.' );
		}

		$hosts = implode( ', ', RegistrationPolicy::hosts() );

		foreach ( $uris as $uri ) {
			if ( ! is_string( $uri ) || ! RegistrationPolicy::allows_redirect( $uri ) ) {
				// Kept in English: it is compared with the app's own documentation when debugging.
				$this->refusals->record(
					Decision::OAUTH_REGISTRATION_HOST,
					$name,
					is_string( $uri ) ? $uri : '',
					'Callback not allowed: ' . ( is_string( $uri ) ? mb_substr( $uri, 0, 200 ) : 'not a string' ) . '. Allowed hosts: ' . $hosts . '.'
				);

				return $this->oauth_error( 'invalid_redirect_uri', 'This site accepts registrations only from Grok (https callbacks on ' . $hosts . ').' );
			}
		}

		$method = (string) ( $body['token_endpoint_auth_method'] ?? 'none' );

		/*
		 * Basic is answered with post. WordPress core treats a Basic header as
		 * an application password and refuses the request before the token
		 * endpoint runs, so a client registered for Basic could never redeem
		 * a code. RFC 7591 §3.2.1 lets the server substitute a value, and the
		 * response says which one it chose.
		 */
		if ( 'client_secret_basic' === $method ) {
			$method = 'client_secret_post';
		}

		if ( ! in_array( $method, array( 'none', 'client_secret_post' ), true ) ) {
			$this->refusals->record( Decision::OAUTH_REGISTRATION_INVALID, $name, $first, 'Unsupported token_endpoint_auth_method: ' . mb_substr( $method, 0, 60 ) . '.' );

			return $this->oauth_error( 'invalid_client_metadata', 'Unsupported token_endpoint_auth_method.' );
		}

		foreach ( (array) ( $body['grant_types'] ?? array() ) as $grant_type ) {
			if ( ! in_array( $grant_type, array( 'authorization_code', 'refresh_token' ), true ) ) {
				$this->refusals->record( Decision::OAUTH_REGISTRATION_INVALID, $name, $first, 'Unsupported grant type: ' . mb_substr( (string) $grant_type, 0, 60 ) . '.' );

				return $this->oauth_error( 'invalid_client_metadata', 'Unsupported grant type.' );
			}
		}

		$stored = $this->store->register_client(
			array(
				'client_name'   => $name,
				'redirect_uris' => array_values( $uris ),
				'auth_method'   => $method,
			)
		);

		if ( is_wp_error( $stored ) ) {
			return $this->oauth_error( 'server_error', $stored->get_error_message(), 500 );
		}

		$client = array(
			'client_id'                  => $stored['client_id'],
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => array_values( $uris ),
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);

		if ( null !== $stored['client_secret'] ) {
			$client['client_secret']            = $stored['client_secret'];
			$client['client_secret_expires_at'] = 0;
		}

		return $this->json( $client, 201 );
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
			return $this->log_token_refusal( $this->exchange_code( $body ), $body );
		}

		if ( 'refresh_token' === $grant ) {
			return $this->log_token_refusal( $this->refresh( $body ), $body );
		}

		return $this->log_token_refusal( $this->oauth_error( 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' ), $body );
	}

	/**
	 * Write a refused token request for the Connect page, then return it unchanged.
	 *
	 * The step after the administrator approves: the app exchanges its code
	 * here. A refusal at this point (redirect URI, PKCE, MCP address) used to
	 * leave no trace, so "approved, then the app said it failed" had nothing
	 * to go on. An unknown client or wrong secret is logged where it is
	 * found (authenticate_client()), so it is not logged twice.
	 *
	 * @param \WP_REST_Response    $response The token endpoint's answer.
	 * @param array<string, mixed> $body     The request body.
	 */
	private function log_token_refusal( \WP_REST_Response $response, array $body ): \WP_REST_Response {
		$data = (array) $response->get_data();

		if ( $response->get_status() < 400 || 'invalid_client' === ( $data['error'] ?? '' ) ) {
			return $response;
		}

		$client_id = (string) ( $body['client_id'] ?? '' );
		$known     = $this->store->find_client( $client_id );

		$this->refusals->record(
			Decision::OAUTH_INVALID_REQUEST,
			null !== $known && '' !== $known['client_name'] ? $known['client_name'] : $client_id,
			(string) ( $body['redirect_uri'] ?? '' ),
			'At the token endpoint (' . (string) ( $body['grant_type'] ?? '' ) . '): ' . (string) ( $data['error'] ?? '' ) . ' — ' . (string) ( $data['error_description'] ?? '' )
		);

		return $response;
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
		 * RFC 8707: the token is for the address that was approved. A code
		 * approved at one MCP address cannot be exchanged for another, such as
		 * an address that replaced it.
		 */
		if ( '' !== $resource && '' !== (string) $row['resource'] && strtolower( untrailingslashit( $resource ) ) !== strtolower( untrailingslashit( (string) $row['resource'] ) ) ) {
			return $this->oauth_error( 'invalid_target', 'This code was approved for a different MCP address.' );
		}

		if ( ! $this->store->consume( (int) $row['id'] ) ) {
			return $this->oauth_error( 'invalid_grant', 'This authorization code has already been used.' );
		}

		// Stored against the agent's record: a credential left from an earlier setup of the plugin does not carry over.
		$agent = $this->agents->get();
		$agent = $agent instanceof Agent && (int) $row['agent_id'] === $agent->get_id() ? $agent : null;

		if ( ! $agent instanceof Agent ) {
			return $this->oauth_error( 'invalid_grant', 'The agent this code was approved for has been removed.' );
		}

		// The agent is not active (now), so the connection gets nothing.
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

		// Stored against the agent's record: a credential left from an earlier setup of the plugin does not carry over.
		$agent = $this->agents->get();
		$agent = $agent instanceof Agent && (int) $row['agent_id'] === $agent->get_id() ? $agent : null;

		// D5: once the agent is revoked, expired or removed, its refresh tokens are dead.
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
		$client_id = (string) ( $body['client_id'] ?? '' );
		$client    = $this->clients->resolve( $client_id );

		if ( is_wp_error( $client ) ) {
			$this->refusals->record( Decision::OAUTH_UNKNOWN_CLIENT, $client_id, '', 'At the token endpoint: ' . $client->get_error_message() );

			return $this->oauth_error( 'invalid_client', $client->get_error_message(), 401 );
		}

		// A metadata document, or a registration with `none`, is a public client: there is no secret to check.
		if ( 'none' !== $client['auth_method'] ) {
			$secret = (string) ( $body['client_secret'] ?? '' );

			if ( null === $client['secret_hash'] || ! $this->store->verify_secret( $secret, (string) $client['secret_hash'] ) ) {
				$this->refusals->record( Decision::OAUTH_INVALID_REQUEST, (string) $client['name'], '', 'At the token endpoint: the client secret was missing or wrong.' );

				return $this->oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
			}
		}

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

	/**
	 * Count a registration against the caller's hourly allowance.
	 */
	private function within_register_limit(): bool {
		$ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$hour = gmdate( 'YmdH' );

		// The site-wide count first: it is what stops a caller with many addresses.
		return self::count_up( 'site', $hour ) <= self::REGISTER_LIMIT_SITE
			&& self::count_up( self::address_bucket( $ip ), $hour ) <= self::REGISTER_LIMIT;
	}

	/**
	 * The bucket an address counts in: itself for IPv4, its /64 for IPv6.
	 *
	 * One IPv6 customer holds a whole /64, so counting single addresses would
	 * give them billions of allowances.
	 *
	 * @param string $ip Remote address.
	 */
	private static function address_bucket( string $ip ): string {
		$packed = false !== strpos( $ip, ':' ) ? inet_pton( $ip ) : false;

		return false !== $packed && 16 === strlen( $packed ) ? 'v6-' . bin2hex( substr( $packed, 0, 8 ) ) : 'v4-' . $ip;
	}

	/**
	 * Add one to an hourly counter and return the new count, atomically.
	 *
	 * One statement increments the stored count, so two requests at once
	 * cannot both read nine and both write ten. Old hours are pruned daily
	 * (OAuthStore::prune()).
	 *
	 * @param string $bucket Who is counted.
	 * @param string $hour   The hour, YmdH.
	 */
	private static function count_up( string $bucket, string $hour ): int {
		global $wpdb;

		$name = 'ai_agent_os_dcr_' . md5( $bucket ) . '_' . $hour;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic counter; no API offers one.
		$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = CAST(option_value AS UNSIGNED) + 1", $wpdb->options, $name ) );

		wp_cache_delete( $name, 'options' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read back the value just written, past every cache.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
	}
}
