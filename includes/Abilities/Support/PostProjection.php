<?php
/**
 * One post, page or custom post type item as an ability returns it.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The single shape every core content ability returns, so an agent learns it once.
 *
 * A password-protected item never carries its content or excerpt: the
 * password is the author's decision about who reads it, and an agent is not
 * someone who typed it.
 */
final class PostProjection {

	/** Statuses the read abilities list. `private` and `trash` are left out on purpose. */
	public const READABLE_STATUSES = array( 'publish', 'draft', 'pending', 'future' );

	/**
	 * The fields a list returns.
	 *
	 * @param \WP_Post $post The item.
	 * @return array<string, mixed>
	 */
	public static function summary( \WP_Post $post ): array {
		$protected = '' !== $post->post_password;

		return array(
			'id'           => (int) $post->ID,
			'type'         => (string) $post->post_type,
			'title'        => (string) get_the_title( $post ),
			'status'       => (string) $post->post_status,
			'slug'         => (string) $post->post_name,
			'link'         => (string) get_permalink( $post ),
			'date_gmt'     => self::date( (string) $post->post_date_gmt ),
			'modified_gmt' => self::date( (string) $post->post_modified_gmt ),
			'author_id'    => (int) $post->post_author,
			'parent_id'    => (int) $post->post_parent,
			'excerpt'      => $protected ? '' : self::excerpt( $post ),
			'has_password' => $protected,
		);
	}

	/**
	 * The fields a single read returns.
	 *
	 * @param \WP_Post $post The item.
	 * @return array<string, mixed>
	 */
	public static function full( \WP_Post $post ): array {
		$protected = '' !== $post->post_password;
		$thumbnail = (int) get_post_thumbnail_id( $post );

		return array_merge(
			self::summary( $post ),
			array(
				'content'           => $protected ? null : (string) $post->post_content,
				'menu_order'        => (int) $post->menu_order,
				'template'          => (string) get_page_template_slug( $post ),
				'featured_media_id' => $thumbnail > 0 ? $thumbnail : null,
				'terms'             => self::terms( $post ),
			)
		);
	}

	/**
	 * Schema of summary().
	 *
	 * @return array<string, mixed>
	 */
	public static function summary_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'           => array( 'type' => 'integer' ),
				'type'         => array( 'type' => 'string' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'slug'         => array( 'type' => 'string' ),
				'link'         => array( 'type' => 'string' ),
				'date_gmt'     => array( 'type' => array( 'string', 'null' ) ),
				'modified_gmt' => array( 'type' => array( 'string', 'null' ) ),
				'author_id'    => array( 'type' => 'integer' ),
				'parent_id'    => array( 'type' => 'integer' ),
				'excerpt'      => array( 'type' => 'string' ),
				'has_password' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * Schema of full().
	 *
	 * @return array<string, mixed>
	 */
	public static function full_schema(): array {
		$schema = self::summary_schema();

		$schema['properties'] += array(
			'content'           => array( 'type' => array( 'string', 'null' ) ),
			'menu_order'        => array( 'type' => 'integer' ),
			'template'          => array( 'type' => 'string' ),
			'featured_media_id' => array( 'type' => array( 'integer', 'null' ) ),
			'terms'             => array(
				'type'                 => 'object',
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => self::term_schema(),
				),
			),
		);

		return $schema;
	}

	/**
	 * One term as the abilities return it.
	 *
	 * @param \WP_Term $term The term.
	 * @return array<string, mixed>
	 */
	public static function term( \WP_Term $term ): array {
		return array(
			'id'          => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'parent_id'   => (int) $term->parent,
			'count'       => (int) $term->count,
		);
	}

	/**
	 * Schema of term().
	 *
	 * @return array<string, mixed>
	 */
	public static function term_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'taxonomy'    => array( 'type' => 'string' ),
				'name'        => array( 'type' => 'string' ),
				'slug'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'parent_id'   => array( 'type' => 'integer' ),
				'count'       => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * The item's terms in supported taxonomies, taxonomy => terms.
	 *
	 * @param \WP_Post $post The item.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function terms( \WP_Post $post ): array {
		$terms = array();

		foreach ( array_keys( ContentTypes::taxonomies_of( (string) $post->post_type ) ) as $taxonomy ) {
			$assigned = get_the_terms( $post, $taxonomy );

			if ( ! is_array( $assigned ) ) {
				continue;
			}

			$terms[ $taxonomy ] = array_values( array_map( array( self::class, 'term' ), $assigned ) );
		}

		return $terms;
	}

	/**
	 * An excerpt from the stored excerpt or the content, without markup.
	 *
	 * @param \WP_Post $post The item.
	 */
	private static function excerpt( \WP_Post $post ): string {
		$text = '' !== $post->post_excerpt ? $post->post_excerpt : strip_shortcodes( $post->post_content );

		return wp_trim_words( wp_strip_all_tags( excerpt_remove_blocks( $text ) ), 55 );
	}

	/**
	 * A GMT date as ISO 8601, or null when WordPress has none yet (an unpublished draft).
	 *
	 * @param string $mysql_gmt The stored GMT date.
	 */
	private static function date( string $mysql_gmt ): ?string {
		if ( '' === $mysql_gmt || '0000-00-00 00:00:00' === $mysql_gmt ) {
			return null;
		}

		return mysql2date( 'Y-m-d\TH:i:s\Z', $mysql_gmt, false );
	}
}
