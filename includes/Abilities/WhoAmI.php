<?php
/**
 * The `ai-agent-os/whoami` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities;

use AiAgentOs\Auth\AgentContext;

defined( 'ABSPATH' ) || exit;

/**
 * The agent's view of itself: name, UUID, type, who it acts for, expiry, masking.
 *
 * A connection check ("am I connected, and as whom?") that touches no store
 * data, and lets the agent state its own boundaries instead of guessing them.
 * The earlier all-in-one plugin had it as `zorem-agent-os/whoami`; the port left
 * that name behind, and the import maps grants on the old name to this one.
 *
 * A tool like any other: it needs a grant, at read.
 */
final class WhoAmI {

	/** Ability name. */
	public const NAME = 'ai-agent-os/whoami';

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_ability' ) );
	}

	/**
	 * Register the ability.
	 */
	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) || wp_has_ability( self::NAME ) ) {
			return;
		}

		wp_register_ability(
			self::NAME,
			array(
				'label'               => __( 'See this agent’s own permissions', 'zorem-ai-agent-connector' ),
				'description'         => __( 'Returns the connected agent\'s name, ID, who it acts for, expiry, and whether customer data is masked for it. On a connection that is not an agent, says which WordPress user it is signed in as.', 'zorem-ai-agent-connector' ),
				'category'            => 'ai-agent-os',
				'input_schema'        => array(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'connected_as' => array(
							'type' => 'string',
							'enum' => array( 'agent', 'user' ),
						),
						'name'         => array( 'type' => 'string' ),
						'uuid'         => array( 'type' => 'string' ),
						'acts_for'     => array( 'type' => 'string' ),
						'expires_at'   => array( 'type' => array( 'string', 'null' ) ),
						'pii_masked'   => array( 'type' => 'boolean' ),
						'note'         => array( 'type' => 'string' ),
					),
				),
				/*
				 * Anyone signed in may ask. Another plugin's MCP connection runs
				 * this tool as the WordPress user it signed in, with no agent;
				 * refusing it there gave only WordPress's generic "does not have
				 * necessary permission", which told nobody why. It now answers
				 * who is connected and says plainly that no agent rules apply.
				 */
				'permission_callback' => static fn(): bool => AgentContext::has() || is_user_logged_in(),
				'execute_callback'    => static function (): array {
					$agent = AgentContext::get();

					if ( null !== $agent ) {
						return array(
							'connected_as' => 'agent',
							'name'         => $agent->get_name(),
							'uuid'         => $agent->get_uuid(),
							'acts_for'     => $agent->get_acts_for(),
							'expires_at'   => $agent->get_expires_at(),
							'pii_masked'   => $agent->masks_pii(),
						);
					}

					$user = wp_get_current_user();
					$name = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;

					return array(
						'connected_as' => 'user',
						'name'         => $name,
						'uuid'         => '',
						'acts_for'     => user_can( $user, 'manage_options' ) ? 'admin' : 'user',
						'expires_at'   => null,
						'pii_masked'   => false,
						'note'         => sprintf(
							/* translators: %s: WordPress user's display name. */
							__( 'This connection is not the site\'s AI agent. It is signed in as the WordPress user "%s", so tools run with that user\'s own WordPress permissions, and the agent\'s permissions and masking do not apply. To connect as the agent, add its MCP address from AI Agent Connector → Agent.', 'zorem-ai-agent-connector' ),
							$name
						),
					);
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'zorem'       => array( 'risk' => 'read' ),
					'mcp'         => array(
						'type'   => 'tool',
						'public' => false,
					),
				),
			)
		);
	}
}
