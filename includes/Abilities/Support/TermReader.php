<?php
/**
 * Listing terms of a taxonomy.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one query path behind get-categories and get-tags.
 */
final class TermReader {

	/**
	 * The input properties a term list accepts.
	 *
	 * @param bool $hierarchical Whether to offer `parent_id`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_properties( bool $hierarchical ): array {
		$properties = array(
			'search'     => Schema::search(),
			'hide_empty' => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Set true to leave out terms no item uses.', 'zorem-ai-agent-connector' ),
			),
		);

		if ( $hierarchical ) {
			$properties['parent_id'] = array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Only direct children of this term ID. Use 0 for top-level terms.', 'zorem-ai-agent-connector' ),
			);
		}

		return $properties + Schema::paging();
	}

	/**
	 * One page of terms.
	 *
	 * @param string               $taxonomy A supported taxonomy.
	 * @param array<string, mixed> $input    Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_terms( string $taxonomy, array $input ) {
		if ( ! ContentTypes::is_supported_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'ai_agent_os_unsupported_taxonomy', __( 'That taxonomy is not supported.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		list( $page, $per_page ) = Schema::paging_from( $input );

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $input['hide_empty'] ),
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['search'] = mb_substr( sanitize_text_field( (string) $input['search'] ), 0, Limits::SEARCH_MAX );
		}

		if ( isset( $input['parent_id'] ) ) {
			$args['parent'] = absint( $input['parent_id'] );
		}

		$total = wp_count_terms( $args );
		$terms = get_terms(
			$args + array(
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		);

		if ( $terms instanceof \WP_Error ) {
			return $terms;
		}

		$items = array();

		foreach ( (array) $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$items[] = PostProjection::term( $term );
			}
		}

		return Schema::page_result( 'terms', $items, is_numeric( $total ) ? (int) $total : count( $items ), $page, $per_page );
	}
}
