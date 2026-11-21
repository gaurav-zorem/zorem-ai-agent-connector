<?php
/**
 * Listing and reading posts, pages and custom post type items.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one query path behind get-posts, get-pages and get-cpt-items, and
 * behind every single-item read.
 *
 * Each item is checked with `read_post` for the current user before it is
 * returned, so the capabilities lent for the call decide what is visible
 * item by item, the same way WordPress's own screens do.
 */
final class PostReader {

	/** Capabilities a post read is lent: other people's and published posts included. */
	public const POST_READ_CAPS = array( 'edit_posts', 'edit_others_posts', 'edit_published_posts' );

	/** Capabilities a page read is lent. */
	public const PAGE_READ_CAPS = array( 'edit_pages', 'edit_others_pages', 'edit_published_pages' );

	/**
	 * The input properties a list of one post type accepts.
	 *
	 * @param bool $hierarchical Whether to offer `parent`.
	 * @return array<string, array<string, mixed>>
	 */
	public static function list_properties( bool $hierarchical ): array {
		$properties = array(
			'search'    => Schema::search(),
			'status'    => array(
				'type'        => 'string',
				'enum'        => PostProjection::READABLE_STATUSES,
				'description' => __( 'Only items with this status. Leave out for published, draft, pending and scheduled items together.', 'zorem-ai-agent-connector' ),
			),
			'author_id' => Schema::id( __( 'Only items by this user ID.', 'zorem-ai-agent-connector' ) ),
		);

		if ( $hierarchical ) {
			$properties['parent_id'] = array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Only direct children of this item ID. Use 0 for top-level items.', 'zorem-ai-agent-connector' ),
			);
		}

		return $properties + Schema::paging();
	}

	/**
	 * One page of items of a post type.
	 *
	 * @param string               $post_type A supported post type.
	 * @param array<string, mixed> $input     Ability input.
	 * @param array<string, mixed> $extra     Extra WP_Query arguments the ability sets itself.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function list_items( string $post_type, array $input, array $extra = array() ) {
		if ( ! ContentTypes::is_supported( $post_type ) ) {
			return self::unsupported( $post_type );
		}

		list( $page, $per_page ) = Schema::paging_from( $input );

		$status = isset( $input['status'] ) && in_array( $input['status'], PostProjection::READABLE_STATUSES, true )
			? array( (string) $input['status'] )
			: PostProjection::READABLE_STATUSES;

		$args = array_merge(
			$extra,
			array(
				'post_type'           => $post_type,
				'post_status'         => $status,
				'posts_per_page'      => $per_page,
				'paged'               => $page,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => false,
			)
		);

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = mb_substr( sanitize_text_field( (string) $input['search'] ), 0, Limits::SEARCH_MAX );
		}

		if ( isset( $input['author_id'] ) ) {
			$args['author'] = absint( $input['author_id'] );
		}

		if ( isset( $input['parent_id'] ) ) {
			$args['post_parent'] = absint( $input['parent_id'] );
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post && current_user_can( 'read_post', $post->ID ) ) {
				$items[] = PostProjection::summary( $post );
			}
		}

		return Schema::page_result( 'items', $items, (int) $query->found_posts, $page, $per_page );
	}

	/**
	 * One item, if it is of an allowed type and the current user may read it.
	 *
	 * Every refusal is "not found": the agent that may not read an item learns
	 * nothing about whether it exists.
	 *
	 * @param int                $id         Item ID.
	 * @param array<int, string> $post_types Allowed post types, or empty for any supported one.
	 * @return \WP_Post|\WP_Error
	 */
	public static function find( int $id, array $post_types = array() ) {
		$post = get_post( $id );

		$allowed = $post instanceof \WP_Post
			&& ContentTypes::is_supported( (string) $post->post_type )
			&& ( array() === $post_types || in_array( $post->post_type, $post_types, true ) )
			&& in_array( $post->post_status, PostProjection::READABLE_STATUSES, true )
			&& current_user_can( 'read_post', $post->ID );

		if ( ! $allowed ) {
			return new \WP_Error( 'ai_agent_os_not_found', __( 'No item with that ID was found.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		return $post;
	}

	/**
	 * The error for a post type the abilities do not touch, with the reason.
	 *
	 * @param string $post_type The post type asked for.
	 */
	public static function unsupported( string $post_type ): \WP_Error {
		return new \WP_Error(
			'ai_agent_os_unsupported_post_type',
			sprintf(
				/* translators: 1: post type name, 2: reason code */
				__( 'Post type "%1$s" is not supported (%2$s). Call list-post-types to see which are.', 'zorem-ai-agent-connector' ),
				$post_type,
				ContentTypes::exclusion_reason( $post_type )
			),
			array( 'status' => 400 )
		);
	}
}
