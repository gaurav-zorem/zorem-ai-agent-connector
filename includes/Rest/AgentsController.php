<?php
/**
 * Agent management endpoints.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Rest;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentFactory;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Governance\AbilityInventory;
use AiAgentOs\Governance\GrantAuthority;
use AiAgentOs\Governance\PermissionRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The site's agent: its settings, its permissions and its token.
 *
 * `manage_agent_os` reaches every route here, and that capability can be handed
 * to a Shop Manager (decision D-7). So the routes that would widen what the
 * agent can do — issuing a credential for it, giving it a new address, turning
 * its destructive switch on, unmasking personal data, changing who it acts for —
 * are held to more than that capability alone. See `authority_over()` and
 * `SITE_FIELDS`.
 */
final class AgentsController extends AbstractAdminController {

	/**
	 * Fields that change what the agent is allowed to do, rather than how it is
	 * described. Only a site administrator may set them.
	 *
	 * `destructive_enabled` lets destructive abilities run; `pii_masked` off
	 * sends customer personal data to the agent's app; `acts_for` decides whose
	 * authority it carries, and admin is unmasked by default.
	 *
	 * @var array<int, string>
	 */
	private const SITE_FIELDS = array( 'destructive_enabled', 'pii_masked', 'acts_for' );

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'check_permission' );

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_agent' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_agent' ),
					'permission_callback' => $permission,
					'args'                => array(
						'name'                => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'status'              => array(
							'type'              => 'string',
							'enum'              => array( Agent::STATUS_ACTIVE, Agent::STATUS_REVOKED, Agent::STATUS_SUSPENDED ),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_key',
						),
						'acts_for'            => array(
							'type'              => 'string',
							'enum'              => Agent::acts_for_values(),
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'sanitize_key',
						),
						'expires_at'          => array( 'type' => array( 'string', 'null' ) ),
						'description'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'pii_masked'          => array( 'type' => 'boolean' ),
						'destructive_enabled' => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)/permissions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_permissions' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_permissions' ),
					'permission_callback' => $permission,
					'args'                => array(
						'grants' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_agent' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)/rotate-token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate_token' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)/mcp-address',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'replace_mcp_address' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace(),
			'/agents/(?P<id>\d+)/revoke-all-permissions',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_all_permissions' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Get the agent.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_agent( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		/**
		 * Permission repository.
		 *
		 * @var PermissionRepository $permissions
		 */
		$permissions = $this->service( PermissionRepository::class );

		return rest_ensure_response(
			array_merge(
				$agent->to_array(),
				array(
					'permission_counts' => $permissions->count_grants( $agent->get_id() ),
					'endpoint'          => $this->service( \AiAgentOs\Mcp\AgentAddress::class )->url( $agent ),
				)
			)
		);
	}

	/**
	 * Update an agent.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_agent( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		/**
		 * Agent repository.
		 *
		 * @var AgentRepository $agents
		 */
		$agents = $this->service( AgentRepository::class );

		/*
		 * The safety switches need an administrator, whoever may otherwise
		 * manage the agent. Checked before anything is written, so a request
		 * carrying one of them changes nothing at all rather than applying the
		 * harmless half.
		 */
		foreach ( self::SITE_FIELDS as $site_field ) {
			if ( $request->has_param( $site_field ) && ! current_user_can( 'manage_options' ) ) {
				return $this->error(
					'ai_agent_os_forbidden',
					__( 'Only a site administrator can change the destructive switch, personal-data masking, or who the agent acts for.', 'zorem-ai-agent-connector' ),
					rest_authorization_required_code()
				);
			}
		}

		$data = array();

		if ( null !== $request->get_param( 'name' ) ) {
			$data['name'] = (string) $request->get_param( 'name' );
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$data['status'] = (string) $request->get_param( 'status' );
		}

		if ( null !== $request->get_param( 'acts_for' ) ) {
			$data['acts_for'] = (string) $request->get_param( 'acts_for' );
		}

		if ( $request->has_param( 'expires_at' ) ) {
			$expires = $request->get_param( 'expires_at' );

			if ( empty( $expires ) ) {
				$data['expires_at'] = null;
			} else {
				$timestamp = strtotime( (string) $expires );

				if ( false === $timestamp ) {
					return $this->error(
						'ai_agent_os_invalid_expiry',
						__( 'That expiry date could not be understood.', 'zorem-ai-agent-connector' )
					);
				}

				$data['expires_at'] = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		if ( $data ) {
			$agents->update( $agent->get_id(), $data );
		}

		$meta_changes = array();

		foreach ( array( 'description', 'pii_masked', 'destructive_enabled' ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$meta_changes[ $key ] = 'description' === $key
					? (string) $request->get_param( $key )
					: (bool) $request->get_param( $key );
			}
		}

		if ( $meta_changes ) {
			$agents->update_meta( $agent->get_id(), $meta_changes );
		}

		$agents->flush_cache();
		$updated = $agents->find( $agent->get_id() );

		return rest_ensure_response( $updated instanceof Agent ? $updated->to_array() : array() );
	}

	/**
	 * The permission matrix for an agent.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_permissions( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		/**
		 * Permission repository.
		 *
		 * @var PermissionRepository $permissions
		 */
		$permissions = $this->service( PermissionRepository::class );
		/**
		 * Ability inventory.
		 *
		 * @var AbilityInventory $inventory
		 */
		$inventory = $this->service( AbilityInventory::class );

		return rest_ensure_response(
			array(
				'agent_id'   => $agent->get_id(),
				'grants'     => $permissions->get_grants( $agent->get_id() ),
				'categories' => $inventory->grouped_descriptors(),
			)
		);
	}

	/**
	 * Write the permission matrix.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_permissions( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$grants = $request->get_param( 'grants' );

		if ( ! is_array( $grants ) ) {
			return $this->error(
				'ai_agent_os_invalid_grants',
				__( 'Permissions must be sent as an object of ability name to grant level.', 'zorem-ai-agent-connector' )
			);
		}

		/**
		 * Permission repository.
		 *
		 * @var PermissionRepository $permissions
		 */
		$permissions = $this->service( PermissionRepository::class );
		/**
		 * Ability inventory.
		 *
		 * @var AbilityInventory $inventory
		 */
		$inventory = $this->service( AbilityInventory::class );
		/**
		 * Grant authority.
		 *
		 * @var GrantAuthority $authority
		 */
		$authority = $this->service( GrantAuthority::class );

		$clean   = array();
		$refused = array();

		foreach ( $grants as $ability_name => $level ) {
			$ability_name = sanitize_text_field( (string) $ability_name );
			$level        = sanitize_key( (string) $level );

			if ( ! in_array( $level, PermissionRepository::levels(), true ) ) {
				continue;
			}

			/*
			 * Refuse to store a grant for an ability that does not exist or is
			 * hidden from every agent. Otherwise the matrix could accumulate
			 * rows that look like access but can never resolve to any.
			 */
			if ( ! $inventory->has( $ability_name ) || $inventory->is_hidden( $ability_name ) ) {
				continue;
			}

			// Only what the person saving could do themselves. An unchanged grant is left as it is.
			if ( ! $authority->may_grant( $ability_name, $level, get_current_user_id() ) ) {
				if ( $permissions->get_grant( $agent->get_id(), $ability_name ) !== $level ) {
					$refused[] = $ability_name;
				}

				continue;
			}

			$clean[ $ability_name ] = $level;
		}

		$written = $permissions->set_grants( $agent->get_id(), $clean, get_current_user_id() );

		return rest_ensure_response(
			array(
				'written' => $written,
				'grants'  => $permissions->get_grants( $agent->get_id() ),
				'refused' => $refused,
				'message' => array() === $refused ? '' : __( 'Some abilities were not granted: they need WordPress capabilities you do not have, or no map says which they need. An administrator can grant them.', 'zorem-ai-agent-connector' ),
			)
		);
	}

	/**
	 * Revoke an agent.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_agent( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		/**
		 * Agent factory.
		 *
		 * @var AgentFactory $factory
		 */
		$factory = $this->service( AgentFactory::class );

		return rest_ensure_response( array( 'revoked' => $factory->revoke( $agent ) ) );
	}

	/**
	 * Issue a replacement token.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rotate_token( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$allowed = $this->authority_over( $agent );

		if ( true !== $allowed ) {
			return $allowed;
		}

		/**
		 * Agent factory.
		 *
		 * @var AgentFactory $factory
		 */
		$factory = $this->service( AgentFactory::class );

		$token = $factory->rotate_token( $agent );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		return rest_ensure_response(
			array(
				'token'      => $token,
				'endpoint'   => $this->service( \AiAgentOs\Mcp\AgentAddress::class )->url( $agent ),
				'token_note' => __( 'Copy this token now. It cannot be shown again. The previous token stopped working immediately.', 'zorem-ai-agent-connector' ),
			)
		);
	}

	/**
	 * Give an agent a new MCP address of its own. The old one stops working at once.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replace_mcp_address( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		$allowed = $this->authority_over( $agent );

		if ( true !== $allowed ) {
			return $allowed;
		}

		/**
		 * Agent address.
		 *
		 * @var \AiAgentOs\Mcp\AgentAddress $addresses
		 */
		$addresses = $this->service( \AiAgentOs\Mcp\AgentAddress::class );

		$addresses->replace( $agent );

		/**
		 * Agent repository.
		 *
		 * @var \AiAgentOs\Agents\AgentRepository $agents
		 */
		$agents = $this->service( \AiAgentOs\Agents\AgentRepository::class );
		$agents->flush_cache();

		$fresh = $agents->find( $agent->get_id() );

		return rest_ensure_response(
			array(
				'url'  => $fresh ? $addresses->url( $fresh ) : '',
				'note' => __( 'This agent has a new MCP address. Clients using the old one must be given the new one; the old address stopped working immediately.', 'zorem-ai-agent-connector' ),
			)
		);
	}

	/**
	 * Set every grant to none.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revoke_all_permissions( \WP_REST_Request $request ) {
		$agent = $this->find_agent( $request );

		if ( is_wp_error( $agent ) ) {
			return $agent;
		}

		/**
		 * Permission repository.
		 *
		 * @var PermissionRepository $permissions
		 */
		$permissions = $this->service( PermissionRepository::class );

		return rest_ensure_response( array( 'revoked' => $permissions->revoke_all( $agent->get_id(), get_current_user_id() ) ) );
	}

	/**
	 * Whether the current user may hand out the agent's existing authority.
	 *
	 * `GrantAuthority` stops a delegated agent manager granting an ability they
	 * could not perform themselves. Issuing a credential for an agent that
	 * already holds such a grant, or giving it a fresh address to sign in at,
	 * reaches the same end by another route: the grant is not changed, but the
	 * person now holds a key to it. So both are held to the same rule — you may
	 * only hand out access you could have granted.
	 *
	 * An administrator passes every check, so this only ever narrows a
	 * delegated manager.
	 *
	 * @param Agent $agent The agent.
	 * @return true|\WP_Error
	 */
	private function authority_over( Agent $agent ) {
		/**
		 * Permission repository.
		 *
		 * @var PermissionRepository $permissions
		 */
		$permissions = $this->service( PermissionRepository::class );
		/**
		 * Grant authority.
		 *
		 * @var GrantAuthority $authority
		 */
		$authority = $this->service( GrantAuthority::class );

		list( , $refused ) = $authority->split( $permissions->get_grants( $agent->get_id() ), get_current_user_id() );

		if ( array() !== $refused ) {
			return $this->error(
				'ai_agent_os_insufficient_authority',
				__( 'This agent is allowed to use tools that need WordPress capabilities you do not have, so you cannot issue credentials for it. An administrator can.', 'zorem-ai-agent-connector' ),
				rest_authorization_required_code()
			);
		}

		return true;
	}

	/**
	 * Load the agent named in the route.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return Agent|\WP_Error
	 */
	private function find_agent( \WP_REST_Request $request ) {
		/**
		 * Agent repository.
		 *
		 * @var AgentRepository $agents
		 */
		$agents = $this->service( AgentRepository::class );

		$agent = $agents->find( (int) $request->get_param( 'id' ) );

		if ( ! $agent instanceof Agent ) {
			return $this->error(
				'ai_agent_os_agent_not_found',
				__( 'That agent does not exist.', 'zorem-ai-agent-connector' ),
				404
			);
		}

		return $agent;
	}
}
