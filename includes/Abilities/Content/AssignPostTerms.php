<?php
/**
 * The `ai-agent-os/assign-post-terms` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Sets a blog post's categories, tags or terms of another supported taxonomy.
 */
final class AssignPostTerms {

	/** Ability name. */
	public const NAME = 'ai-agent-os/assign-post-terms';

	/** Capabilities lent for the call. */
	public const CAPS = PostReader::POST_READ_CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Assign post terms', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Sets a blog post\'s terms in one taxonomy (for example "category" or "post_tag"), replacing them unless append is true.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => self::input_schema( __( 'The post ID, from get-posts.', 'zorem-ai-agent-connector' ) ),
			'output_schema'       => self::output_schema(),
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
		return self::assign( is_array( $input ) ? $input : array(), array( 'post' ) );
	}

	/**
	 * Input schema, shared with assign-cpt-terms.
	 *
	 * @param string $id_description What the ID identifies.
	 * @return array<string, mixed>
	 */
	public static function input_schema( string $id_description ): array {
		return Schema::input(
			array(
				'id'       => Schema::id( $id_description ),
				'taxonomy' => Schema::key( __( 'The taxonomy name, for example "category" or "post_tag".', 'zorem-ai-agent-connector' ) ),
				'term_ids' => PostWriter::term_ids_property( __( 'Term IDs of that taxonomy. An empty list removes every term unless append is true.', 'zorem-ai-agent-connector' ) ),
				'append'   => array(
					'type'        => 'boolean',
					'default'     => false,
					'description' => __( 'Set true to add these terms to the existing ones instead of replacing them.', 'zorem-ai-agent-connector' ),
				),
			),
			array( 'id', 'taxonomy', 'term_ids' )
		);
	}

	/**
	 * Output schema, shared with assign-cpt-terms.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'integer' ),
				'taxonomy' => array( 'type' => 'string' ),
				'terms'    => array(
					'type'  => 'array',
					'items' => PostProjection::term_schema(),
				),
			),
		);
	}

	/**
	 * Assign terms to an item of one of the given types.
	 *
	 * @param array<string, mixed> $input      Ability input.
	 * @param array<int, string>   $post_types Allowed post types, or empty for any supported one.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function assign( array $input, array $post_types ) {
		$post = PostReader::find( absint( $input['id'] ?? 0 ), $post_types );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		$taxonomy = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$assigned = PostWriter::assign_terms( (int) $post->ID, $taxonomy, (array) ( $input['term_ids'] ?? array() ), ! empty( $input['append'] ) );

		if ( $assigned instanceof \WP_Error ) {
			return $assigned;
		}

		$terms = get_the_terms( $post->ID, $taxonomy );

		return array(
			'id'       => (int) $post->ID,
			'taxonomy' => $taxonomy,
			'terms'    => is_array( $terms ) ? array_values( array_map( array( PostProjection::class, 'term' ), $terms ) ) : array(),
		);
	}
}
