<?php
/**
 * Finding a comment the comment write abilities may change.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One comment, if it is on supported content, of a type the abilities
 * handle, not in the trash, and editable by the current user.
 *
 * Every refusal is "not found", as for posts.
 */
final class CommentFinder {

	/**
	 * Capabilities a comment write is lent.
	 *
	 * `edit_comment` resolves to editing the post the comment is on, so the
	 * post and page edit capabilities are needed beside `moderate_comments`.
	 */
	public const CAPS = array( 'moderate_comments', ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/** Comment types the abilities change. Block notes and WooCommerce reviews are left out. */
	public const TYPES = array( 'comment', 'pingback', 'trackback' );

	/**
	 * The comment, or a not-found error.
	 *
	 * @param int $id Comment ID.
	 * @return \WP_Comment|\WP_Error
	 */
	public static function find( int $id ) {
		$comment = get_comment( $id );
		$post    = $comment instanceof \WP_Comment ? get_post( (int) $comment->comment_post_ID ) : null;
		$type    = $comment instanceof \WP_Comment && '' !== $comment->comment_type ? $comment->comment_type : 'comment';

		$allowed = $comment instanceof \WP_Comment
			&& $post instanceof \WP_Post
			&& ContentTypes::is_supported( (string) $post->post_type )
			&& in_array( $type, self::TYPES, true )
			&& 'trash' !== wp_get_comment_status( $comment )
			&& current_user_can( 'edit_comment', $comment->comment_ID );

		if ( ! $allowed ) {
			return new \WP_Error( 'ai_agent_os_not_found', __( 'No comment with that ID was found.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		return $comment;
	}
}
