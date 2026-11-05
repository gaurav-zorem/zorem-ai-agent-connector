<?php
/**
 * The `ai-agent-os/update-post` ability.
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
 * Changes a blog post. Only the fields sent are changed.
 */
final class UpdatePost {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-post';

	/** Capabilities lent for the call. */
	public const CAPS = PostWriter::POST_WRITE_CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array( 'id' => Schema::id( __( 'The post ID, from get-posts.', 'zorem-ai-agent-connector' ) ) ) + CreatePost::properties( false );

		return array(
			'label'               => __( 'Update post', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Changes a blog post\'s title, content, excerpt, status, categories, tags or featured image. Only the fields sent are changed; content and term lists replace what is there.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( $properties, array( 'id' ) ),
			'output_schema'       => PostProjection::full_schema(),
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
		$input = is_array( $input ) ? $input : array();

		return PostWriter::update( absint( $input['id'] ?? 0 ), array( 'post' ), $input, CreatePost::terms_setter( $input ) );
	}
}
