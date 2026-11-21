<?php
/**
 * The `ai-agent-os/moderate-comment` ability.
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
 * Approves a comment, holds it for moderation, marks it spam or trashes it.
 *
 * Rated write. The input key is `status`, so a call that sets `trash` is
 * escalated to destructive by RiskClassifier::input_is_destructive() and
 * needs destructive mode, like an order cancellation does.
 */
final class ModerateComment {

	/** Ability name. */
	public const NAME = 'ai-agent-os/moderate-comment';

	/** Capabilities lent for the call. */
	public const CAPS = CommentFinder::CAPS;

	/** Status values, input => wp_set_comment_status() value. */
	private const STATUSES = array(
		'approved' => 'approve',
		'pending'  => 'hold',
		'spam'     => 'spam',
		'trash'    => 'trash',
	);

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Approve, hold or remove a comment', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Sets a comment\'s status: approved, pending (held for moderation), spam or trash. Trashing needs destructive mode.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-comments',
			'input_schema'        => Schema::input(
				array(
					'id'     => Schema::id( __( 'The comment ID, from get-comments.', 'zorem-ai-agent-connector' ) ),
					'status' => array(
						'type'        => 'string',
						'enum'        => array_keys( self::STATUSES ),
						'description' => __( 'The new status. Use "spam" only for unsolicited advertising or abuse, not for criticism.', 'zorem-ai-agent-connector' ),
					),
				),
				array( 'id', 'status' )
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

		$status = self::STATUSES[ (string) ( $input['status'] ?? '' ) ] ?? null;

		if ( null === $status ) {
			return new \WP_Error( 'ai_agent_os_invalid_status', __( 'That status cannot be set.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$result = wp_set_comment_status( $comment, $status, true );

		if ( $result instanceof \WP_Error ) {
			return $result;
		}

		$updated = get_comment( $comment->comment_ID );

		return $updated instanceof \WP_Comment ? GetComments::project( $updated ) : CommentFinder::find( 0 );
	}
}
