<?php
/**
 * The `ai-agent-os/duplicate-post` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\ItemAuthor;
use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Copies a post, page or supported custom post type item into a new draft.
 *
 * The copy gets the content, excerpt, parent, order, template, featured
 * image, terms in supported taxonomies and unprotected meta. Protected meta
 * (edit locks, other plugins' internal state) is not copied: carrying it
 * into a second item is how a copy ends up sharing an order number, a
 * licence key or a cached render with its original. The copy is always a
 * draft by the agent, and keeps the original's password if it had one.
 * The content is saved through the same markup filter as the agent write,
 * so markup only an administrator may post (scripts, iframes) is dropped
 * from the copy.
 */
final class DuplicatePost {

	/** Ability name. */
	public const NAME = 'ai-agent-os/duplicate-post';

	/** Capabilities lent for the call: reading the original and creating a draft of either base type. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Make a copy of a post or page', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Copies a post, page or custom post type item, with its content, terms, featured image and custom fields, into a new draft.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input(
				array(
					'id'    => Schema::id( __( 'The ID of the item to copy.', 'zorem-ai-agent-connector' ) ),
					'title' => array(
						'type'        => 'string',
						'maxLength'   => PostWriter::TITLE_MAX,
						'description' => __( 'Title for the copy. Leave out to use the original title followed by "(copy)".', 'zorem-ai-agent-connector' ),
					),
				),
				array( 'id' )
			),
			'output_schema'       => PostProjection::full_schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_post( $input ) && Access::can_create_in( (string) get_post_type( Access::id( $input ) ) ),
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
		$input    = is_array( $input ) ? $input : array();
		$original = PostReader::find( absint( $input['id'] ?? 0 ) );

		if ( $original instanceof \WP_Error ) {
			return $original;
		}

		$type = get_post_type_object( $original->post_type );

		if ( ! $type instanceof \WP_Post_Type || ! current_user_can( $type->cap->create_posts ) ) {
			return PostWriter::forbidden();
		}

		$title = isset( $input['title'] ) && '' !== trim( (string) $input['title'] )
			? mb_substr( sanitize_text_field( (string) $input['title'] ), 0, PostWriter::TITLE_MAX )
			/* translators: %s: the original title */
			: sprintf( __( '%s (copy)', 'zorem-ai-agent-connector' ), $original->post_title );

		$postarr = array(
			'post_type'      => $original->post_type,
			'post_status'    => 'draft',
			'post_title'     => $title,
			'post_content'   => $original->post_content,
			'post_excerpt'   => $original->post_excerpt,
			'post_parent'    => $original->post_parent,
			'menu_order'     => $original->menu_order,
			'post_password'  => $original->post_password,
			'comment_status' => $original->comment_status,
			'ping_status'    => $original->ping_status,
			'page_template'  => (string) get_page_template_slug( $original ),
		);

		$author = ItemAuthor::for_new_item( $type );

		if ( $author > 0 ) {
			$postarr['post_author'] = $author;
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );

		if ( $id instanceof \WP_Error ) {
			return $id;
		}

		self::copy_terms( $original, (int) $id );
		self::copy_meta( $original, (int) $id );

		$thumbnail = (int) get_post_thumbnail_id( $original );

		if ( $thumbnail > 0 ) {
			set_post_thumbnail( (int) $id, $thumbnail );
		}

		$copy = get_post( (int) $id );

		return $copy instanceof \WP_Post ? PostProjection::full( $copy ) : PostWriter::forbidden();
	}

	/**
	 * Copy the original's terms in supported taxonomies.
	 *
	 * @param \WP_Post $original The original.
	 * @param int      $id       The copy.
	 */
	private static function copy_terms( \WP_Post $original, int $id ): void {
		foreach ( array_keys( ContentTypes::taxonomies_of( (string) $original->post_type ) ) as $taxonomy ) {
			$term_ids = wp_get_object_terms( $original->ID, $taxonomy, array( 'fields' => 'ids' ) );

			if ( is_array( $term_ids ) && array() !== $term_ids ) {
				wp_set_object_terms( $id, array_map( 'intval', $term_ids ), $taxonomy );
			}
		}
	}

	/**
	 * Copy the original's unprotected meta, value for value.
	 *
	 * @param \WP_Post $original The original.
	 * @param int      $id       The copy.
	 */
	private static function copy_meta( \WP_Post $original, int $id ): void {
		foreach ( array_keys( (array) get_post_meta( $original->ID ) ) as $key ) {
			$key = (string) $key;

			if ( is_protected_meta( $key, 'post' ) ) {
				continue;
			}

			foreach ( (array) get_post_meta( $original->ID, $key, false ) as $value ) {
				add_post_meta( $id, $key, wp_slash( $value ) );
			}
		}
	}
}
