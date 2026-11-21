<?php
/**
 * The `ai-agent-os/site-statistics` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Core\Installer;
use AiAgentOs\Governance\RiskClassifier;

/**
 * How much content the site has: counts only, never the content itself.
 */
final class SiteStatistics {

	/** Ability name. */
	public const NAME = 'ai-agent-os/site-statistics';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts' );

	/** Post statuses counted. */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$by_status = array(
			'type'       => 'object',
			'properties' => array_fill_keys( self::STATUSES, array( 'type' => 'integer' ) ),
		);

		return array(
			'label'               => __( 'See site totals (posts, pages, users)', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Counts the site\'s content: posts, pages and custom post type items by status, comments by status, media files, categories, tags and users.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => Schema::input( array() ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'content'    => array(
						'type'                 => 'object',
						'additionalProperties' => $by_status,
					),
					'comments'   => array(
						'type'       => 'object',
						'properties' => array(
							'approved' => array( 'type' => 'integer' ),
							'pending'  => array( 'type' => 'integer' ),
							'spam'     => array( 'type' => 'integer' ),
						),
					),
					'media'      => array( 'type' => 'integer' ),
					'categories' => array( 'type' => 'integer' ),
					'tags'       => array( 'type' => 'integer' ),
					'users'      => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static fn(): bool => Access::can_list_any(),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute(): array {
		$content = array();

		foreach ( array_keys( ContentTypes::supported() ) as $post_type ) {
			$counts = wp_count_posts( $post_type );
			$row    = array();

			foreach ( self::STATUSES as $status ) {
				$row[ $status ] = (int) ( $counts->$status ?? 0 );
			}

			$content[ $post_type ] = $row;
		}

		$comments = wp_count_comments();
		$media    = (array) wp_count_posts( 'attachment' );
		$users    = count_users();
		$agents   = (int) ( $users['avail_roles'][ Installer::AGENT_ROLE ] ?? 0 );

		return array(
			'content'    => $content,
			'comments'   => array(
				'approved' => (int) $comments->approved,
				'pending'  => (int) $comments->moderated,
				'spam'     => (int) $comments->spam,
			),
			'media'      => (int) ( $media['inherit'] ?? 0 ),
			'categories' => self::term_count( 'category' ),
			'tags'       => self::term_count( 'post_tag' ),
			'users'      => max( 0, (int) $users['total_users'] - $agents ),
		);
	}

	/**
	 * How many terms a taxonomy has.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	private static function term_count( string $taxonomy ): int {
		$count = wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
