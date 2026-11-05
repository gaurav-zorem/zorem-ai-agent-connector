<?php
/**
 * The `ai-agent-os/get-posts` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\Limits;
use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Lists blog posts, newest first, with an excerpt but no full content.
 */
final class GetPosts {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-posts';

	/** Capabilities lent for the call. */
	public const CAPS = PostReader::POST_READ_CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = PostReader::list_properties( false ) + array(
			'category' => Schema::key( __( 'Only posts in this category slug.', 'zorem-ai-agent-connector' ) ),
			'tag'      => Schema::key( __( 'Only posts with this tag slug.', 'zorem-ai-agent-connector' ) ),
		);

		return array(
			'label'               => __( 'Get posts', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists blog posts (published, draft, pending or scheduled), newest first, with title, status, link and excerpt. Call get-post for the full content.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( $properties ),
			'output_schema'       => Schema::page_of( 'items', PostProjection::summary_schema() ),
			'permission_callback' => static fn(): bool => Access::can_list( 'post' ),
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
		$extra = array();

		if ( isset( $input['category'] ) ) {
			$extra['category_name'] = mb_substr( sanitize_title( (string) $input['category'] ), 0, Limits::KEY_MAX );
		}

		if ( isset( $input['tag'] ) ) {
			$extra['tag'] = mb_substr( sanitize_title( (string) $input['tag'] ), 0, Limits::KEY_MAX );
		}

		return PostReader::list_items( 'post', $input, $extra );
	}
}
