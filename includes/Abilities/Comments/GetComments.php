<?php
/**
 * The `ai-agent-os/get-comments` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Comments;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Lists comments on posts, pages and supported custom post types.
 *
 * Never returns the commenter's IP address or browser: an agent moderating
 * comments needs what was said and by whom, not where they were. The name
 * and email are under keys the PII masker knows (`display_name`, `email`),
 * so an agent that masks customer data sees them masked.
 */
final class GetComments {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-comments';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'moderate_comments' );

	/** Comment types returned. Block notes and WooCommerce reviews are left out. */
	private const TYPES = array( 'comment', 'pingback', 'trackback' );

	/** Status filter values, input => WP_Comment_Query status. */
	private const STATUSES = array(
		'approved' => 'approve',
		'pending'  => 'hold',
		'spam'     => 'spam',
	);

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get comments', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists comments, newest first, with the post they are on, author name and email, content and status.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-comments',
			'input_schema'        => Schema::input(
				array(
					'post_id' => Schema::id( __( 'Only comments on this post or page ID.', 'zorem-ai-agent-connector' ) ),
					'status'  => array(
						'type'        => 'string',
						'enum'        => array_keys( self::STATUSES ),
						'description' => __( 'Only comments with this status. Leave out for approved and pending together.', 'zorem-ai-agent-connector' ),
					),
				) + Schema::paging()
			),
			'output_schema'       => Schema::page_of( 'comments', self::comment_schema() ),
			'permission_callback' => static fn( $input = null ): bool => Access::can_read_comments( $input ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = null ): array {
		$input = is_array( $input ) ? $input : array();

		list( $page, $per_page ) = Schema::paging_from( $input );

		$args = array(
			'status'    => self::STATUSES[ (string) ( $input['status'] ?? '' ) ] ?? 'all',
			'type__in'  => self::TYPES,
			'post_type' => array_keys( ContentTypes::supported() ),
			'orderby'   => 'comment_date_gmt',
			'order'     => 'DESC',
		);

		if ( isset( $input['post_id'] ) ) {
			$args['post_id'] = absint( $input['post_id'] );
		}

		$total    = (int) ( new \WP_Comment_Query() )->query( $args + array( 'count' => true ) );
		$comments = ( new \WP_Comment_Query() )->query(
			$args + array(
				'number' => $per_page,
				'offset' => ( $page - 1 ) * $per_page,
			)
		);

		$items = array();

		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$items[] = self::project( $comment );
			}
		}

		return Schema::page_result( 'comments', $items, $total, $page, $per_page );
	}

	/**
	 * One comment as the comment abilities return it.
	 *
	 * @param \WP_Comment $comment The comment.
	 * @return array<string, mixed>
	 */
	public static function project( \WP_Comment $comment ): array {
		$status = wp_get_comment_status( $comment );

		return array(
			'id'        => (int) $comment->comment_ID,
			'post_id'   => (int) $comment->comment_post_ID,
			'parent_id' => (int) $comment->comment_parent,
			'type'      => '' === $comment->comment_type ? 'comment' : (string) $comment->comment_type,
			'status'    => 'unapproved' === $status ? 'pending' : (string) $status,
			'date_gmt'  => mysql2date( 'Y-m-d\TH:i:s\Z', (string) $comment->comment_date_gmt, false ),
			'content'   => (string) $comment->comment_content,
			'author'    => array(
				'user_id'      => (int) $comment->user_id,
				'display_name' => (string) $comment->comment_author,
				'email'        => (string) $comment->comment_author_email,
				'url'          => (string) $comment->comment_author_url,
			),
		);
	}

	/**
	 * Schema of project().
	 *
	 * @return array<string, mixed>
	 */
	public static function comment_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'        => array( 'type' => 'integer' ),
				'post_id'   => array( 'type' => 'integer' ),
				'parent_id' => array( 'type' => 'integer' ),
				'type'      => array( 'type' => 'string' ),
				'status'    => array( 'type' => 'string' ),
				'date_gmt'  => array( 'type' => 'string' ),
				'content'   => array( 'type' => 'string' ),
				'author'    => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'      => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'email'        => array( 'type' => 'string' ),
						'url'          => array( 'type' => 'string' ),
					),
				),
			),
		);
	}
}
