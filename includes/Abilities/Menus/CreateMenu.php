<?php
/**
 * The `ai-agent-os/create-menu` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Menus;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Creates an empty navigation menu.
 *
 * The menu is not put in any theme location: which menu the site shows is a
 * person's decision (assign-menu-location is not one of these abilities).
 */
final class CreateMenu {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-menu';

	/** Capabilities lent for the call. */
	public const CAPS = ListMenus::CAPS;

	/** Longest menu name. */
	public const NAME_MAX = 200;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Create menu', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates an empty navigation menu. Add items with add-menu-item. It is not shown on the site until a person assigns it to a menu location.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-menus',
			'input_schema'        => Schema::input(
				array(
					'name' => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::NAME_MAX,
						'description' => __( 'The menu name. Must not match an existing menu.', 'zorem-ai-agent-connector' ),
					),
				),
				array( 'name' )
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'   => array( 'type' => 'integer' ),
					'name' => array( 'type' => 'string' ),
					'slug' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_WRITE, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$name = mb_substr( sanitize_text_field( (string) ( is_array( $input ) ? ( $input['name'] ?? '' ) : '' ) ), 0, self::NAME_MAX );

		if ( '' === $name ) {
			return new \WP_Error( 'ai_agent_os_invalid_name', __( 'A name is required.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$id = wp_create_nav_menu( $name );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$menu = wp_get_nav_menu_object( (int) $id );

		if ( ! $menu instanceof \WP_Term ) {
			return new \WP_Error( 'ai_agent_os_save_failed', __( 'WordPress did not save the menu.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
		}

		return array(
			'id'   => (int) $menu->term_id,
			'name' => (string) $menu->name,
			'slug' => (string) $menu->slug,
		);
	}
}
