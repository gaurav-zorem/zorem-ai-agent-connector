<?php
/**
 * The `ai-agent-os/get-cpt-taxonomies` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * The taxonomies of one supported post type, with their term counts.
 */
final class GetCptTaxonomies {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-cpt-taxonomies';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get custom post type taxonomies', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists the taxonomies (categories, tags and custom ones) a post type uses, with label, whether they are hierarchical and how many terms each has.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input(
				array( 'post_type' => Schema::key( __( 'The post type name, from list-post-types.', 'zorem-ai-agent-connector' ) ) ),
				array( 'post_type' )
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'post_type'  => array( 'type' => 'string' ),
					'taxonomies' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'hierarchical' => array( 'type' => 'boolean' ),
								'term_count'   => array( 'type' => 'integer' ),
							),
						),
					),
				),
			),
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
		$post_type = sanitize_key( (string) ( is_array( $input ) ? ( $input['post_type'] ?? '' ) : '' ) );

		if ( ! ContentTypes::is_supported( $post_type ) ) {
			return PostReader::unsupported( $post_type );
		}

		$taxonomies = array();

		foreach ( ContentTypes::taxonomies_of( $post_type ) as $name => $object ) {
			$count = wp_count_terms(
				array(
					'taxonomy'   => $name,
					'hide_empty' => false,
				)
			);

			$taxonomies[] = array(
				'name'         => $name,
				'label'        => (string) $object->labels->name,
				'hierarchical' => (bool) $object->hierarchical,
				'term_count'   => is_numeric( $count ) ? (int) $count : 0,
			);
		}

		return array(
			'post_type'  => $post_type,
			'taxonomies' => $taxonomies,
		);
	}
}
