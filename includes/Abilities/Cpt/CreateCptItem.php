<?php
/**
 * The `ai-agent-os/create-cpt-item` ability.
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
 * Creates an item of any supported post type, as a draft unless told otherwise.
 */
final class CreateCptItem {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-cpt-item';

	/** Capabilities lent for the call: a supported type uses the post or the page ones. */
	public const CAPS = array( ...PostWriter::POST_WRITE_CAPS, ...PostWriter::PAGE_WRITE_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array(
			'post_type' => Schema::key( __( 'The post type name, from list-post-types.', 'zorem-ai-agent-connector' ) ),
		) + PostWriter::properties( true ) + PostWriter::hierarchy_properties();

		return array(
			'label'               => __( 'Create custom post type item', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates an item of a post type from list-post-types (including "page"), with title, content, excerpt, parent and featured image. It is saved as a draft unless status says otherwise. Set its terms afterwards with assign-cpt-terms.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input( $properties, array( 'post_type', 'title' ) ),
			'output_schema'       => PostProjection::full_schema(),
			'permission_callback' => static fn(): bool => Access::can_create_any(),
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

		return PostWriter::create( sanitize_key( (string) ( $input['post_type'] ?? '' ) ), $input );
	}
}
