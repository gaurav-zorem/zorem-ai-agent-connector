<?php
/**
 * The `ai-agent-os/get-cpt-item` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * One item of any supported post type, with its full content and terms.
 */
final class GetCptItem {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-cpt-item';

	/** Capabilities lent for the call. */
	public const CAPS = GetCptItems::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get custom post type item', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Returns one item of any supported post type by ID: title, status, full content, parent, template, featured image ID and terms. Password-protected items return no content.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The item ID, from get-cpt-items.', 'zorem-ai-agent-connector' ) ) ),
				array( 'id' )
			),
			'output_schema'       => PostProjection::full_schema(),

			/*
			 * Coarse here on purpose. `PostReader::find()` makes the
			 * per-object `read_post` check and answers every refusal with
			 * "not found", so an agent that may not read an item learns
			 * nothing about whether it exists. Refusing at the permission
			 * stage instead would answer "no permission", which tells the
			 * caller the item is there.
			 */
			'permission_callback' => static fn(): bool => Access::can_list_any(),
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
		$post = PostReader::find( absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 ) );

		return $post instanceof \WP_Error ? $post : PostProjection::full( $post );
	}
}
