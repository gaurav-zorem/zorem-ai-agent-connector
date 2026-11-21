<?php
/**
 * The `ai-agent-os/delete-post` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostTrasher;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Moves a blog post to the trash. Never deletes it permanently.
 */
final class DeletePost {

	/** Ability name. */
	public const NAME = 'ai-agent-os/delete-post';

	/** Capabilities lent for the call: finding the post, then trashing it. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, 'delete_posts', 'delete_others_posts', 'delete_published_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Move a blog post to the trash', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Moves a blog post to the trash, where a person can restore it. It is never deleted permanently.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The post ID, from get-posts.', 'zorem-ai-agent-connector' ) ) ),
				array( 'id' )
			),
			'output_schema'       => PostTrasher::schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_delete_post( $input ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_DESTRUCTIVE, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		return PostTrasher::trash( absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 ), array( 'post' ) );
	}
}
