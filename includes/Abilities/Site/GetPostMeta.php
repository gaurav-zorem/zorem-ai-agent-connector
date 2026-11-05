<?php
/**
 * The `ai-agent-os/get-post-meta` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\Limits;
use AiAgentOs\Abilities\Support\MetaReader;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * A post's, page's or custom post type item's unprotected meta.
 */
final class GetPostMeta {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-post-meta';

	/** Capabilities lent for the call. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get post meta', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Returns the custom fields (meta) of a post, page or custom post type item. Values are text; structured values are JSON.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => Schema::input(
				array(
					'id'  => Schema::id( __( 'The post, page or item ID.', 'zorem-ai-agent-connector' ) ),
					'key' => MetaReader::key_property(),
				),
				array( 'id' )
			),
			'output_schema'       => MetaReader::schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_post( $input ),
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
		$input = is_array( $input ) ? $input : array();
		$post  = PostReader::find( absint( $input['id'] ?? 0 ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		/*
		 * Checked again here, not only in the permission callback: `find()`
		 * establishes that the item exists and is readable, and a published
		 * post is readable by anyone. Custom fields are not published content.
		 */
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		$key = mb_substr( (string) ( $input['key'] ?? '' ), 0, Limits::KEY_MAX );

		return array( 'id' => (int) $post->ID ) + MetaReader::read( 'post', $key, (array) get_post_meta( $post->ID ) );
	}
}
