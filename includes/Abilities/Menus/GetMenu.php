<?php
/**
 * The `ai-agent-os/get-menu` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Menus;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * One navigation menu's items, in menu order.
 *
 * Items come back as a flat list with `parent_id`, in the order the menu
 * shows them: a nested schema would have to be recursive, which the
 * Abilities API's validator does not follow.
 */
final class GetMenu {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-menu';

	/** Capabilities lent for the call. */
	public const CAPS = ListMenus::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get menu', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Returns one navigation menu\'s items in display order: title, URL, what each links to, and parent_id (0 for a top-level item).', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-menus',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The menu ID, from list-menus.', 'zorem-ai-agent-connector' ) ) ),
				array( 'id' )
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'    => array( 'type' => 'integer' ),
					'name'  => array( 'type' => 'string' ),
					'items' => array(
						'type'  => 'array',
						'items' => self::item_schema(),
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
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$id   = absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 );
		$menu = wp_get_nav_menu_object( $id );

		if ( ! $menu instanceof \WP_Term ) {
			return new \WP_Error( 'ai_agent_os_not_found', __( 'No menu with that ID was found.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		$items = array();

		foreach ( (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'publish' ) ) as $item ) {
			if ( is_object( $item ) && isset( $item->ID ) ) {
				$items[] = self::project( $item );
			}
		}

		return array(
			'id'    => (int) $menu->term_id,
			'name'  => (string) $menu->name,
			'items' => $items,
		);
	}

	/**
	 * One menu item as the menu abilities return it.
	 *
	 * @param object $item A menu item from wp_setup_nav_menu_item().
	 * @return array<string, mixed>
	 */
	public static function project( object $item ): array {
		return array(
			'id'        => (int) $item->ID,
			'title'     => (string) ( $item->title ?? '' ),
			'url'       => (string) ( $item->url ?? '' ),
			'type'      => (string) ( $item->type ?? '' ),
			'object'    => (string) ( $item->object ?? '' ),
			'object_id' => (int) ( $item->object_id ?? 0 ),
			'parent_id' => (int) ( $item->menu_item_parent ?? 0 ),
			'position'  => (int) ( $item->menu_order ?? 0 ),
		);
	}

	/**
	 * Schema of project().
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'title'     => array( 'type' => 'string' ),
				'url'       => array( 'type' => 'string' ),
				'type'      => array( 'type' => 'string' ),
				'object'    => array( 'type' => 'string' ),
				'object_id' => array( 'type' => 'integer' ),
				'parent_id' => array( 'type' => 'integer' ),
				'position'  => array( 'type' => 'integer' ),
			),
		);
	}
}
