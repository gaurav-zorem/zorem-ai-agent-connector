<?php
/**
 * Binds an inbound request to the agent identity.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Agents\ShadowUserManager;
use AiAgentOs\Governance\Decision;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the resolver chain and establishes request context.
 *
 * Two entry points, because the two credential types need different timing:
 *
 *   - Bearer tokens must resolve on determine_current_user, since they are the
 *     thing that decides who the current user is.
 *   - Application passwords are validated by WordPress core, which has already
 *     set the user by the time REST starts. There we only observe that the
 *     established user is the agent's shadow user.
 *
 * Both run before mcp_adapter_init (rest_api_init priority 15), so the server
 * is always built with the agent already known.
 */
final class AgentAuthenticator {

	/**
	 * Bearer token resolver.
	 *
	 * @var BearerTokenResolver
	 */
	private BearerTokenResolver $bearer;

	/**
	 * Agent persistence.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Connect-flow access token resolver.
	 *
	 * @var OAuthAccessTokenResolver
	 */
	private OAuthAccessTokenResolver $oauth;

	/**
	 * Constructor.
	 *
	 * @param BearerTokenResolver      $bearer Bearer token resolver.
	 * @param AgentRepository          $agents Agent persistence.
	 * @param OAuthAccessTokenResolver $oauth  Connect-flow access token resolver.
	 */
	public function __construct( BearerTokenResolver $bearer, AgentRepository $agents, OAuthAccessTokenResolver $oauth ) {
		$this->bearer = $bearer;
		$this->agents = $agents;
		$this->oauth  = $oauth;
	}

	/**
	 * Hook the authenticator.
	 */
	public function register(): void {
		// Priority 30: after core's application-password handling at 20.
		add_filter( 'determine_current_user', array( $this, 'determine_current_user' ), 30 );
		// Last: whoever resolved it, the agent's shadow user is only ever an identity on the agent doors.
		add_filter( 'determine_current_user', array( $this, 'confine_shadow_users' ), 1000 );
		add_action( 'rest_api_init', array( $this, 'bind_application_password_agent' ), 1 );

		/*
		 * XML-RPC, wp-login and anything else that signs in through
		 * wp_authenticate() never pass determine_current_user, and an
		 * application password works there. A shadow user is refused at the
		 * end of that chain too.
		 */
		add_filter( 'authenticate', array( $this, 'refuse_shadow_sign_in' ), 1000 );

		// And once more just before a REST route runs, whatever hook timing let it through.
		add_filter( 'rest_pre_dispatch', array( $this, 'refuse_stray_shadow_user' ), 0, 3 );
	}

	/**
	 * Refuse a shadow user signing in anywhere but the agent doors.
	 *
	 * @param \WP_User|\WP_Error|null $user The user signed in so far.
	 * @return \WP_User|\WP_Error|null
	 */
	public function refuse_shadow_sign_in( $user ) {
		if ( ! $user instanceof \WP_User || ! ShadowUserManager::is_agent_user( (int) $user->ID ) ) {
			return $user;
		}

		if ( Routes::is_governed_request() ) {
			return $user;
		}

		return new \WP_Error( 'ai_agent_os_agent_sign_in', __( 'This account belongs to an AI agent and cannot sign in here.', 'zorem-ai-agent-connector' ) );
	}

	/**
	 * Refuse a REST route to a shadow user unless it is its MCP address.
	 *
	 * Only the MCP route is governed. Everything else — WooCommerce's REST API
	 * and its own MCP route included — is not, so the agent may not use it.
	 *
	 * @param mixed            $result  Response so far.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function refuse_stray_shadow_user( $result, $server, $request ) {
		unset( $server );

		$user_id = get_current_user_id();

		if ( null !== $result || $user_id < 1 || ! $request instanceof \WP_REST_Request || ! ShadowUserManager::is_agent_user( $user_id ) ) {
			return $result;
		}

		$route = strtolower( $request->get_route() );

		if ( Routes::is_mcp_route( $route ) ) {
			return $result;
		}

		return new \WP_Error( 'ai_agent_os_agent_route', __( 'The agent may use only its MCP address.', 'zorem-ai-agent-connector' ), array( 'status' => 403 ) );
	}

	/**
	 * Resolve a bearer token to the agent and adopt its shadow user.
	 *
	 * @param int|false $user_id User id resolved so far.
	 * @return int|false
	 */
	public function determine_current_user( $user_id ) {
		if ( ! Routes::is_governed_request() ) {
			return $user_id;
		}

		/*
		 * Agent tokens and connect-flow access tokens are told apart by prefix,
		 * so at most one resolver ever claims a request.
		 */
		$resolver = null;

		foreach ( array( $this->bearer, $this->oauth ) as $candidate ) {
			if ( $candidate->supports() ) {
				$resolver = $candidate;
				break;
			}
		}

		if ( null === $resolver ) {
			/*
			 * Nothing we accept. With no Authorization header at all, the
			 * likely cause is the web server dropping it before PHP runs
			 * (CGI and FastCGI hosts do), so that is recorded apart from a
			 * header we do not recognise. An application password that core
			 * accepted is bound later, on rest_api_init, and clears neither.
			 */
			AgentContext::set_credential_problem( $this->bearer->has_authorization_header() ? Decision::INVALID_TOKEN : Decision::NO_CREDENTIALS );

			return $user_id;
		}

		$agent = $resolver->resolve();

		if ( ! $agent instanceof Agent ) {
			/*
			 * A token was presented and rejected. Leave the user unchanged
			 * rather than falling back to whatever session may exist: the
			 * caller asked to be treated as the agent, so it gets the agent's
			 * answer, which here is "no". The resolver's reason survives the
			 * reset; a resolver that gave none refused an unknown token.
			 */
			$problem  = AgentContext::credential_problem();
			$named_id = AgentContext::credential_problem_agent_id();

			AgentContext::reset();
			AgentContext::set_credential_problem( '' !== $problem ? $problem : Decision::INVALID_TOKEN, $named_id );

			return $user_id;
		}

		/*
		 * Bind the context even for an inactive agent, so the refusal is logged
		 * against the agent that was actually presented rather than as an
		 * anonymous bad token.
		 */
		AgentContext::set( $agent );
		AgentContext::set_channel( 'mcp' );

		$shadow_id = $agent->get_shadow_user_id();

		/*
		 * Adopt the shadow user only when the agent may act. A revoked or
		 * expired agent is identified but never authenticated: TransportPermission
		 * refuses the request before dispatch, and leaving the current user
		 * unchanged means nothing downstream can mistake it for a live session.
		 */
		if ( ! $agent->is_active() ) {
			return $user_id;
		}

		/*
		 * The agent with no account of its own must not inherit whoever is
		 * already signed in: its calls would run, and be logged, as that
		 * person. It runs as nobody, and Agent::inactive_reason() refuses it.
		 */
		if ( $shadow_id < 1 ) {
			return false;
		}

		return $shadow_id;
	}

	/**
	 * Refuse the agent's shadow user anywhere but its MCP address.
	 *
	 * That is the one door the agent is governed on. (Until 1.0.1 WooCommerce's
	 * REST API was a second one, for keys issued to the agent; it was removed,
	 * and the agent reaches the store only through MCP abilities.) A shadow
	 * user can still be resolved elsewhere: an application password on one
	 * works on every REST route, and a WooCommerce key someone made for it by
	 * hand would match `/wp-json/wc/`. Nothing there logs or decides the
	 * agent's calls, so the identity is dropped and the request runs as nobody.
	 *
	 * @param int|false $user_id User id resolved so far.
	 * @return int|false
	 */
	public function confine_shadow_users( $user_id ) {
		if ( ! $user_id || Routes::is_governed_request() ) {
			return $user_id;
		}

		// By the account's link to its agent, not its role: changing the role must not set it free.
		if ( ShadowUserManager::is_agent_user( (int) $user_id ) ) {
			return false;
		}

		return $user_id;
	}

	/**
	 * Recognise the agent behind an application-password session.
	 */
	public function bind_application_password_agent(): void {
		if ( AgentContext::has() || ! Routes::is_governed_request() ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return;
		}

		$agent = $this->agents->get_by_shadow_user( $user_id );

		if ( ! $agent instanceof Agent ) {
			return;
		}

		// Bound even when inactive, so the refusal names the agent. Core has
		// already authenticated the shadow user here, but TransportPermission
		// still refuses the request before it reaches a tool.
		AgentContext::set( $agent );
		AgentContext::set_channel( 'mcp' );
	}
}
