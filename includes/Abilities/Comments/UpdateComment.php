<?php
/**
 * The `ai-agent-os/update-comment` ability.
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
 * Changes a comment's text. Nothing else about it.
 *
 * The author's name, email and address stay as the commenter gave them: an
 * agent correcting a typo has no reason to change who said something.
 */
final class UpdateComment {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-comment';

	/** Capabilities lent for the call. */
	public const CAPS = CommentFinder::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Edit a comment', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Replaces a comment\'s text, for example to fix a typo in a reply or remove a link. The author is left as it is.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-comments',
			'input_schema'        => Schema::input(
				array(
					'id'      => Schema::id( __( 'The comment ID, from get-comments.', 'zorem-ai-agent-connector' ) ),
					'content' => ReplyToComment::content_property( __( 'The whole new text of the comment.', 'zorem-ai-agent-connector' ) ),
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
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$input   = is_array( $input ) ? $input : array();
		$comment = CommentFinder::find( absint( $input['id'] ?? 0 ) );

		if ( $comment instanceof \WP_Error ) {
			return $comment;
		}

		$content = trim( wp_kses_data( mb_substr( (string) ( $input['content'] ?? '' ), 0, ReplyToComment::CONTENT_MAX ) ) );

		if ( '' === $content ) {
			return new \WP_Error( 'ai_agent_os_empty_comment', __( 'The new text is empty.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$result = wp_update_comment(
			wp_slash(
				array(
					'comment_ID'      => (int) $comment->comment_ID,
					'comment_content' => $content,
				)
			),
			true
		);

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$updated = get_comment( $comment->comment_ID );

		return $updated instanceof \WP_Comment ? GetComments::project( $updated ) : CommentFinder::find( 0 );
	}
}
