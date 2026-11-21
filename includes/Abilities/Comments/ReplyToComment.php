<?php
/**
 * The `ai-agent-os/reply-to-comment` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Comments;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\CommentFinder;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Posts an approved reply to a comment, as the agent.
 *
 * The reply is signed with the agent's own (shadow) user, so the site shows
 * who wrote it rather than attributing it to a person. Content is filtered to
 * the markup WordPress allows in comments.
 */
final class ReplyToComment {

	/** Ability name. */
	public const NAME = 'ai-agent-os/reply-to-comment';

	/** Capabilities lent for the call. */
	public const CAPS = CommentFinder::CAPS;

	/** Longest reply. */
	public const CONTENT_MAX = 5000;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Reply to a comment', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Posts a reply to a comment, published straight away and signed with the agent\'s name.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-comments',
			'input_schema'        => Schema::input(
				array(
					'id'      => Schema::id( __( 'The ID of the comment to reply to, from get-comments.', 'zorem-ai-agent-connector' ) ),
					'content' => self::content_property( __( 'The reply. It is public as soon as it is posted.', 'zorem-ai-agent-connector' ) ),
				),
				array( 'id', 'content' )
			),
			'output_schema'       => GetComments::comment_schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_comment( $input ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_WRITE, self::CAPS ),
		);
	}

	/**
	 * The `content` property of a comment write.
	 *
	 * @param string $description Instruction for the model.
	 * @return array<string, mixed>
	 */
	public static function content_property( string $description ): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => self::CONTENT_MAX,
			'description' => $description,
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$input  = is_array( $input ) ? $input : array();
		$parent = CommentFinder::find( absint( $input['id'] ?? 0 ) );

		if ( $parent instanceof \WP_Error ) {
			return $parent;
		}

		$content = trim( wp_kses_data( mb_substr( (string) ( $input['content'] ?? '' ), 0, self::CONTENT_MAX ) ) );

		if ( '' === $content ) {
			return new \WP_Error( 'ai_agent_os_empty_reply', __( 'The reply is empty.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'comment_content'      => $content,
					'comment_approved'     => 1,
					'comment_type'         => 'comment',
					'user_id'              => (int) $user->ID,
					'comment_author'       => (string) $user->display_name,
					'comment_author_email' => (string) $user->user_email,
					'comment_author_url'   => '',
				)
			)
		);

		$reply = false === $id ? null : get_comment( (int) $id );

		if ( ! $reply instanceof \WP_Comment ) {
			return new \WP_Error( 'ai_agent_os_save_failed', __( 'WordPress did not save the reply.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
		}

		return GetComments::project( $reply );
	}
}
