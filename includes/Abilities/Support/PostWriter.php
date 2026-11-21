<?php
/**
 * Creating and updating posts, pages and custom post type items.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one write path behind create/update post, page and CPT item.
 *
 * What it will not do: set an author, a password, a date, a slug of someone
 * else's choosing for another item, or a status other than draft, pending or
 * publish. Private and scheduled items stay a person's decision. Content is
 * passed through wp_kses_post(), and the agent never holds `unfiltered_html`, so
 * WordPress filters it again on save.
 */
final class PostWriter {

	/** Statuses a write may set. */
	public const WRITABLE_STATUSES = array( 'draft', 'pending', 'publish' );

	/** Capabilities a post write is lent. */
	public const POST_WRITE_CAPS = array( 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts' );

	/** Capabilities a page write is lent. */
	public const PAGE_WRITE_CAPS = array( 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages' );

	/** Longest title. */
	public const TITLE_MAX = 300;

	/** Longest content, in characters (well under MySQL LONGTEXT; a page is rarely a tenth of this). */
	public const CONTENT_MAX = 200000;

	/** Longest excerpt. */
	public const EXCERPT_MAX = 2000;

	/** Most term IDs one write assigns per taxonomy. */
	public const TERMS_MAX = 50;

	/**
	 * Input properties shared by every create and update.
	 *
	 * @param bool $creating Whether the item is new (it then defaults to draft).
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties( bool $creating ): array {
		return array(
			'title'             => array(
				'type'        => 'string',
				'maxLength'   => self::TITLE_MAX,
				'description' => __( 'The title, as plain text.', 'zorem-ai-agent-connector' ),
			),
			'content'           => array(
				'type'        => 'string',
				'maxLength'   => self::CONTENT_MAX,
				'description' => __( 'The full content as HTML or block markup. Replaces the existing content entirely, so send all of it.', 'zorem-ai-agent-connector' ),
			),
			'excerpt'           => array(
				'type'        => 'string',
				'maxLength'   => self::EXCERPT_MAX,
				'description' => __( 'A short summary, as plain text.', 'zorem-ai-agent-connector' ),
			),
			'status'            => array(
				'type'        => 'string',
				'enum'        => self::WRITABLE_STATUSES,
				'description' => $creating
					? __( 'Leave out to save a draft. Use "publish" only when the user asked for it to go live.', 'zorem-ai-agent-connector' )
					: __( 'Leave out to keep the current status. Use "publish" only when the user asked for it to go live.', 'zorem-ai-agent-connector' ),
			),
			'featured_media_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'ID of an image in the media library to use as the featured image, or 0 to remove it.', 'zorem-ai-agent-connector' ),
			),
		);
	}

	/**
	 * Hierarchy properties for page-like types.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function hierarchy_properties(): array {
		return array(
			'parent_id'  => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'ID of the parent item, or 0 for a top-level item.', 'zorem-ai-agent-connector' ),
			),
			'menu_order' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'maximum'     => 100000,
				'description' => __( 'Sort position among siblings; lower comes first.', 'zorem-ai-agent-connector' ),
			),
			'template'   => Schema::key( __( 'A page template slug the theme offers, or "default".', 'zorem-ai-agent-connector' ) ),
		);
	}

	/**
	 * Create an item.
	 *
	 * @param string               $post_type A supported post type.
	 * @param array<string, mixed> $input     Ability input.
	 * @param callable|null        $after     Run with the new ID before the result is built, to set terms.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function create( string $post_type, array $input, ?callable $after = null ) {
		if ( ! ContentTypes::is_supported( $post_type ) ) {
			return PostReader::unsupported( $post_type );
		}

		$type = get_post_type_object( $post_type );

		if ( ! $type instanceof \WP_Post_Type || ! current_user_can( $type->cap->create_posts ) ) {
			return self::forbidden();
		}

		$postarr = self::fields( $type, $input, array( 'post_status' => 'draft' ) );

		if ( $postarr instanceof \WP_Error ) {
			return $postarr;
		}

		$postarr['post_type'] = $post_type;

		$id = wp_insert_post( wp_slash( $postarr ), true );

		return self::finish( $id, $input, $after );
	}

	/**
	 * Update an item.
	 *
	 * @param int                  $id         Item ID.
	 * @param array<int, string>   $post_types Allowed post types, or empty for any supported one.
	 * @param array<string, mixed> $input      Ability input.
	 * @param callable|null        $after      Run with the ID before the result is built.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function update( int $id, array $post_types, array $input, ?callable $after = null ) {
		$post = PostReader::find( $id, $post_types );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return self::forbidden();
		}

		$type    = get_post_type_object( $post->post_type );
		$postarr = $type instanceof \WP_Post_Type ? self::fields( $type, $input, array() ) : self::forbidden();

		if ( $postarr instanceof \WP_Error ) {
			return $postarr;
		}

		$postarr['ID'] = $post->ID;

		$result = wp_update_post( wp_slash( $postarr ), true );

		return self::finish( $result, $input, $after );
	}

	/**
	 * The post array for the fields an input sets.
	 *
	 * @param \WP_Post_Type        $type     The item's post type.
	 * @param array<string, mixed> $input    Ability input.
	 * @param array<string, mixed> $defaults Fields to start from.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function fields( \WP_Post_Type $type, array $input, array $defaults ) {
		$postarr = $defaults;

		if ( isset( $input['title'] ) ) {
			$postarr['post_title'] = mb_substr( sanitize_text_field( (string) $input['title'] ), 0, self::TITLE_MAX );
		}

		if ( isset( $input['content'] ) ) {
			$postarr['post_content'] = wp_kses_post( mb_substr( (string) $input['content'], 0, self::CONTENT_MAX ) );
		}

		if ( isset( $input['excerpt'] ) ) {
			$postarr['post_excerpt'] = mb_substr( sanitize_textarea_field( (string) $input['excerpt'] ), 0, self::EXCERPT_MAX );
		}

		if ( isset( $input['status'] ) ) {
			if ( ! in_array( $input['status'], self::WRITABLE_STATUSES, true ) ) {
				return new \WP_Error( 'ai_agent_os_invalid_status', __( 'That status cannot be set.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$postarr['post_status'] = (string) $input['status'];
		}

		if ( 'publish' === ( $postarr['post_status'] ?? '' ) && ! current_user_can( $type->cap->publish_posts ) ) {
			return self::forbidden();
		}

		if ( isset( $input['parent_id'] ) && $type->hierarchical ) {
			$parent = absint( $input['parent_id'] );

			if ( $parent > 0 && PostReader::find( $parent, array( $type->name ) ) instanceof \WP_Error ) {
				return new \WP_Error( 'ai_agent_os_invalid_parent', __( 'The parent was not found.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$postarr['post_parent'] = $parent;
		}

		if ( isset( $input['featured_media_id'] ) && absint( $input['featured_media_id'] ) > 0 && ! wp_attachment_is_image( absint( $input['featured_media_id'] ) ) ) {
			return new \WP_Error( 'ai_agent_os_invalid_media', __( 'The featured image must be an image in the media library.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		if ( isset( $input['menu_order'] ) ) {
			$postarr['menu_order'] = absint( $input['menu_order'] );
		}

		if ( isset( $input['template'] ) ) {
			$template = sanitize_text_field( (string) $input['template'] );

			if ( 'default' !== $template && ! array_key_exists( $template, wp_get_theme()->get_page_templates( null, $type->name ) ) ) {
				return new \WP_Error( 'ai_agent_os_invalid_template', __( 'The theme offers no template with that slug.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$postarr['page_template'] = $template;
		}

		return $postarr;
	}

	/**
	 * Apply what wp_insert_post() does not, then build the result.
	 *
	 * @param int|\WP_Error        $id    What the save returned.
	 * @param array<string, mixed> $input Ability input.
	 * @param callable|null        $after Run with the ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function finish( $id, array $input, ?callable $after ) {
		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		$id = (int) $id;

		if ( $id < 1 ) {
			return new \WP_Error( 'ai_agent_os_save_failed', __( 'WordPress did not save the item.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
		}

		if ( isset( $input['featured_media_id'] ) ) {
			$media = absint( $input['featured_media_id'] );

			if ( 0 === $media ) {
				delete_post_thumbnail( $id );
			} else {
				set_post_thumbnail( $id, $media );
			}
		}

		if ( null !== $after ) {
			$applied = $after( $id );

			if ( $applied instanceof \WP_Error ) {
				return $applied;
			}
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post ? PostProjection::full( $post ) : new \WP_Error( 'ai_agent_os_save_failed', __( 'WordPress did not save the item.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
	}

	/**
	 * Assign terms of a supported taxonomy to an item.
	 *
	 * Each ID must be a term of that taxonomy; an unknown ID refuses the whole
	 * assignment rather than assigning the rest.
	 *
	 * @param int             $id       Item ID.
	 * @param string          $taxonomy Taxonomy.
	 * @param array<int, int> $term_ids Term IDs.
	 * @param bool            $append   Add to the existing terms rather than replace them.
	 * @return true|\WP_Error
	 */
	public static function assign_terms( int $id, string $taxonomy, array $term_ids, bool $append ) {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || ! ContentTypes::is_supported_taxonomy( $taxonomy, (string) $post->post_type ) ) {
			return new \WP_Error( 'ai_agent_os_unsupported_taxonomy', __( 'That taxonomy is not supported for this item.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$term_ids = array_slice( array_values( array_unique( array_map( 'absint', $term_ids ) ) ), 0, self::TERMS_MAX );

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );

			if ( ! $term instanceof \WP_Term ) {
				return new \WP_Error(
					'ai_agent_os_unknown_term',
					/* translators: 1: term ID, 2: taxonomy name */
					sprintf( __( 'There is no term %1$d in %2$s.', 'zorem-ai-agent-connector' ), $term_id, $taxonomy ),
					array( 'status' => 400 )
				);
			}
		}

		$result = wp_set_object_terms( $id, $term_ids, $taxonomy, $append );

		return $result instanceof \WP_Error ? $result : true;
	}

	/**
	 * Schema of a term-ID list property.
	 *
	 * @param string $description What the terms are for, as an instruction.
	 * @return array<string, mixed>
	 */
	public static function term_ids_property( string $description ): array {
		return array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'maxItems'    => self::TERMS_MAX,
			'description' => $description,
		);
	}

	/**
	 * The refusal for something the lent capabilities do not cover.
	 */
	public static function forbidden(): \WP_Error {
		return new \WP_Error( 'ai_agent_os_forbidden', __( 'This item cannot be changed with this ability.', 'zorem-ai-agent-connector' ), array( 'status' => 403 ) );
	}
}
