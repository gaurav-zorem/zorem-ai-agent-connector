<?php
/**
 * The `ai-agent-os/update-menu-item` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Menus;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\MenuItemWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Changes a navigation menu item. Only the fields sent are changed.
 */
final class UpdateMenuItem {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-menu-item';

	/** Capabilities lent for the call. */
	public const CAPS = ListMenus::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array(
			'menu_id' => Schema::id( __( 'The menu ID, from list-menus.', 'zorem-ai-agent-connector' ) ),
			'item_id' => Schema::id( __( 'The item ID, from get-menu.', 'zorem-ai-agent-connector' ) ),
		) + MenuItemWriter::properties();

		return array(
			'label'               => __( 'Edit a menu link', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Changes a menu item\'s title, link, parent or position. Only the fields sent are changed.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-menus',
			'input_schema'        => Schema::input( $properties, array( 'menu_id', 'item_id' ) ),
			'output_schema'       => GetMenu::item_schema(),
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
		$input = is_array( $input ) ? $input : array();

		return MenuItemWriter::save( absint( $input['menu_id'] ?? 0 ), absint( $input['item_id'] ?? 0 ), $input );
	}
}
