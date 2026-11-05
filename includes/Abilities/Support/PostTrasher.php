<?php
/**
 * Moving posts, pages and custom post type items to the trash.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one delete path, and it only ever trashes.
 *
 * There is no "force" argument anywhere (PATTERNS 12): a parameter that can
 * make a delete permanent is a parameter an injected instruction can try.
 * If the site has switched the trash off (EMPTY_TRASH_DAYS is 0),
 * wp_trash_post() would delete outright, so the call is refused instead.
 */
final class PostTrasher {

	/**
	 * Trash an item.
	 *
	 * @param int                $id         Item ID.
	 * @param array<int, string> $post_types Allowed post types, or empty for any supported one.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function trash( int $id, array $post_types ) {
		if ( ! EMPTY_TRASH_DAYS ) {
			return new \WP_Error( 'ai_agent_os_trash_disabled', __( 'This site has the trash switched off, so deleting would be permanent. Ask a person to delete it.', 'zorem-ai-agent-connector' ), array( 'status' => 409 ) );
		}

		$post = PostReader::find( $id, $post_types );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		$trashed = wp_trash_post( $post->ID );

		if ( ! $trashed instanceof \WP_Post ) {
			return new \WP_Error( 'ai_agent_os_trash_failed', __( 'WordPress did not move the item to the trash.', 'zorem-ai-agent-connector' ), array( 'status' => 500 ) );
		}

		return array(
			'id'     => (int) $post->ID,
			'type'   => (string) $post->post_type,
			'title'  => (string) get_the_title( $post ),
			'status' => 'trash',
		);
	}

	/**
	 * Schema of trash().
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'     => array( 'type' => 'integer' ),
				'type'   => array( 'type' => 'string' ),
				'title'  => array( 'type' => 'string' ),
				'status' => array( 'type' => 'string' ),
			),
		);
	}
}
