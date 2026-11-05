<?php
/**
 * The `ai-agent-os/update-cpt-item` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Changes an item of any supported post type, pages included. Only the fields sent are changed.
 */
final class UpdateCptItem {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-cpt-item';

	/** Capabilities lent for the call. */
	public const CAPS = CreateCptItem::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array(
			'id' => Schema::id( __( 'The item ID, from get-cpt-items or get-pages.', 'zorem-ai-agent-connector' ) ),
		) + PostWriter::properties( false ) + PostWriter::hierarchy_properties();

		return array(
			'label'               => __( 'Update custom post type item', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Changes a page or custom post type item\'s title, content, excerpt, status, parent, order, template or featured image. Only the fields sent are changed; content replaces what is there.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input( $properties, array( 'id' ) ),
			'output_schema'       => PostProjection::full_schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_post( $input ),
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

		return PostWriter::update( absint( $input['id'] ?? 0 ), array(), $input );
	}
}
