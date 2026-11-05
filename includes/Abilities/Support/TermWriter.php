<?php
/**
 * Creating and changing terms.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one write path behind create-category, create-tag and update-term.
 */
final class TermWriter {

	/** Longest term name. */
	public const NAME_MAX = 200;

	/** Longest term description. */
	public const DESCRIPTION_MAX = 2000;

	/**
	 * Input properties of a term.
	 *
	 * @param bool $hierarchical Whether to offer `parent_id`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties( bool $hierarchical ): array {
		$properties = array(
			'name'        => array(
				'type'        => 'string',
				'minLength'   => 1,
				'maxLength'   => self::NAME_MAX,
				'description' => __( 'The name, as people will see it.', 'zorem-ai-agent-connector' ),
			),
			'slug'        => Schema::key( __( 'The URL slug. Leave out to make one from the name.', 'zorem-ai-agent-connector' ) ),
			'description' => array(
				'type'        => 'string',
				'maxLength'   => self::DESCRIPTION_MAX,
				'description' => __( 'A description, as plain text.', 'zorem-ai-agent-connector' ),
			),
		);

		if ( $hierarchical ) {
			$properties['parent_id'] = array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'ID of the parent term, or 0 for a top-level term.', 'zorem-ai-agent-connector' ),
			);
		}

		return $properties;
	}

	/**
	 * The term fields an input sets.
	 *
	 * @param string               $taxonomy A supported taxonomy.
	 * @param array<string, mixed> $input    Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function fields( string $taxonomy, array $input ) {
		$args = array();

		if ( isset( $input['name'] ) ) {
			$args['name'] = mb_substr( sanitize_text_field( (string) $input['name'] ), 0, self::NAME_MAX );
		}

		if ( isset( $input['slug'] ) ) {
			$args['slug'] = sanitize_title( (string) $input['slug'] );
		}

		if ( isset( $input['description'] ) ) {
			$args['description'] = mb_substr( sanitize_textarea_field( (string) $input['description'] ), 0, self::DESCRIPTION_MAX );
		}

		if ( isset( $input['parent_id'] ) && is_taxonomy_hierarchical( $taxonomy ) ) {
			$parent = absint( $input['parent_id'] );

			if ( $parent > 0 && ! get_term( $parent, $taxonomy ) instanceof \WP_Term ) {
				return new \WP_Error( 'ai_agent_os_invalid_parent', __( 'The parent term was not found.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$args['parent'] = $parent;
		}

		return $args;
	}

	/**
	 * Create a term.
	 *
	 * @param string               $taxonomy A supported taxonomy.
	 * @param array<string, mixed> $input    Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( string $taxonomy, array $input ) {
		$object = get_taxonomy( $taxonomy );

		if ( ! $object instanceof \WP_Taxonomy || ! ContentTypes::is_supported_taxonomy( $taxonomy ) || ! current_user_can( $object->cap->edit_terms ) ) {
			return PostWriter::forbidden();
		}

		$args = self::fields( $taxonomy, $input );

		if ( $args instanceof \WP_Error ) {
			return $args;
		}

		$name = (string) ( $args['name'] ?? '' );

		unset( $args['name'] );

		if ( '' === $name ) {
			return new \WP_Error( 'ai_agent_os_invalid_name', __( 'A name is required.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$created = wp_insert_term( wp_slash( $name ), $taxonomy, wp_slash( $args ) );

		if ( $created instanceof \WP_Error ) {
			return $created;
		}

		$term = get_term( (int) $created['term_id'], $taxonomy );

		return $term instanceof \WP_Term ? PostProjection::term( $term ) : PostWriter::forbidden();
	}
}
