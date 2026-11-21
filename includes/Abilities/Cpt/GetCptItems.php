<?php
/**
 * The `ai-agent-os/get-cpt-items` ability.
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
 * Lists items of one supported post type.
 */
final class GetCptItems {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-cpt-items';

	/** Capabilities lent for the call: a supported type uses the post or the page ones. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array(
			'post_type' => Schema::key( __( 'The post type name, from list-post-types.', 'zorem-ai-agent-connector' ) ),
		) + PostReader::list_properties( true );

		return array(
			'label'               => __( 'See items of a content type', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists items of one post type (published, draft, pending or scheduled), newest first, with title, status, link and excerpt. Call get-cpt-item for the full content.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input( $properties, array( 'post_type' ) ),
			'output_schema'       => Schema::page_of( 'items', PostProjection::summary_schema() ),
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
		$input = is_array( $input ) ? $input : array();

		return PostReader::list_items( sanitize_key( (string) ( $input['post_type'] ?? '' ) ), $input );
	}
}
