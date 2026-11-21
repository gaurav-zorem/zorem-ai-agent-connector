<?php
/**
 * The `ai-agent-os/create-post` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Creates a blog post, as a draft unless told otherwise.
 */
final class CreatePost {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-post';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts', 'publish_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Write a new blog post (draft)', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates a blog post with title, content, excerpt, categories, tags and featured image. It is saved as a draft unless status says otherwise.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( self::properties(), array( 'title' ) ),
			'output_schema'       => PostProjection::full_schema(),
			'permission_callback' => static fn(): bool => Access::can_create_in( 'post' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_WRITE, self::CAPS ),
		);
	}

	/**
	 * Input properties, shared with update-post.
	 *
	 * @param bool $creating Whether the post is new.
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties( bool $creating = true ): array {
		return PostWriter::properties( $creating ) + array(
			'category_ids' => PostWriter::term_ids_property( __( 'Category IDs from get-categories. Replaces the post\'s categories.', 'zorem-ai-agent-connector' ) ),
			'tag_ids'      => PostWriter::term_ids_property( __( 'Tag IDs from get-tags (create-tag adds a new one). Replaces the post\'s tags.', 'zorem-ai-agent-connector' ) ),
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

		return PostWriter::create( 'post', $input, self::terms_setter( $input ) );
	}

	/**
	 * Sets the categories and tags an input names.
	 *
	 * @param array<string, mixed> $input Ability input.
	 */
	public static function terms_setter( array $input ): callable {
		return static function ( int $id ) use ( $input ) {
			foreach ( array(
				'category_ids' => 'category',
				'tag_ids'      => 'post_tag',
			) as $key => $taxonomy ) {
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}

				$assigned = PostWriter::assign_terms( $id, $taxonomy, (array) $input[ $key ], false );

				if ( $assigned instanceof \WP_Error ) {
					return $assigned;
				}
			}

			return true;
		};
	}
}
