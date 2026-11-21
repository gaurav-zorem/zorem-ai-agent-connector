<?php
/**
 * The `ai-agent-os/list-menus` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Menus;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * The site's navigation menus and the theme locations they fill.
 */
final class ListMenus {

	/** Ability name. */
	public const NAME = 'ai-agent-os/list-menus';

	/** Capabilities lent for the call: what Appearance → Menus asks for. */
	public const CAPS = array( 'edit_theme_options' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See menus', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists the navigation menus with their item counts, and the theme\'s menu locations with the menu assigned to each. Call get-menu for a menu\'s items.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-menus',
			'input_schema'        => Schema::input( array() ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'menus'     => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'id'         => array( 'type' => 'integer' ),
								'name'       => array( 'type' => 'string' ),
								'slug'       => array( 'type' => 'string' ),
								'item_count' => array( 'type' => 'integer' ),
								'locations'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'locations' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'slug'    => array( 'type' => 'string' ),
								'label'   => array( 'type' => 'string' ),
								'menu_id' => array( 'type' => array( 'integer', 'null' ) ),
							),
						),
					),
				),
			),
			'permission_callback' => static fn(): bool => current_user_can( 'edit_theme_options' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute(): array {
		$assigned  = array_map( 'intval', (array) get_nav_menu_locations() );
		$locations = array();

		foreach ( (array) get_registered_nav_menus() as $slug => $label ) {
			$menu_id     = $assigned[ $slug ] ?? 0;
			$locations[] = array(
				'slug'    => (string) $slug,
				'label'   => (string) $label,
				'menu_id' => $menu_id > 0 ? $menu_id : null,
			);
		}

		$menus = array();

		foreach ( wp_get_nav_menus() as $menu ) {
			if ( ! $menu instanceof \WP_Term ) {
				continue;
			}

			$menus[] = array(
				'id'         => (int) $menu->term_id,
				'name'       => (string) $menu->name,
				'slug'       => (string) $menu->slug,
				'item_count' => (int) $menu->count,
				'locations'  => array_map( 'strval', array_keys( $assigned, (int) $menu->term_id, true ) ),
			);
		}

		return array(
			'menus'     => $menus,
			'locations' => $locations,
		);
	}
}
