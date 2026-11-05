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
 * An agent's view of itself: name, UUID, type, who it acts for, expiry, masking.
 *
 * A connection check ("am I connected, and as whom?") that touches no store
 * data, and lets an agent state its own boundaries instead of guessing them.
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
				'label'               => __( 'Who am I', 'zorem-ai-agent-connector' ),
				'description'         => __( 'Returns the connected agent\'s name, ID, who it acts for, expiry, and whether customer data is masked for it.', 'zorem-ai-agent-connector' ),
				'category'            => 'ai-agent-os',
				'input_schema'        => array(),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'name'       => array( 'type' => 'string' ),
						'uuid'       => array( 'type' => 'string' ),
						'acts_for'   => array( 'type' => 'string' ),
						'expires_at' => array( 'type' => array( 'string', 'null' ) ),
						'pii_masked' => array( 'type' => 'boolean' ),
					),
				),
				'permission_callback' => static fn(): bool => AgentContext::has(),
				'execute_callback'    => static function (): array {
					$agent = AgentContext::get();

					if ( null === $agent ) {
						return array();
					}

					return array(
						'name'       => $agent->get_name(),
						'uuid'       => $agent->get_uuid(),
						'acts_for'   => $agent->get_acts_for(),
						'expires_at' => $agent->get_expires_at(),
						'pii_masked' => $agent->masks_pii(),
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
