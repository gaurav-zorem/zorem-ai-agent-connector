<?php
/**
 * The `ai-agent-os/search-replace` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Replaces text in one post's, page's or item's content.
 *
 * One item at a time, by design. A site-wide replace is a different and
 * destructive operation (it rewrites serialised options and other plugins'
 * data); this never touches anything but the content of the item named.
 * The text matched is literal, not a pattern. The save goes through
 * wp_update_post(), so a revision is kept and the change can be undone.
 * Like every write the agent makes, the saved content passes the markup filter, so a
 * replacement cannot add a script.
 */
final class SearchReplace {

	/** Ability name. */
	public const NAME = 'ai-agent-os/search-replace';

	/** Capabilities lent for the call. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/** Most replacements one call makes. A call that would make more is refused. */
	public const MAX_REPLACEMENTS = 1000;

	/** Longest search text. */
	public const SEARCH_MAX = 1000;

	/** Longest replacement text. */
	public const REPLACE_MAX = 5000;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Find and replace text across the site', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Replaces every occurrence of a piece of text in one post\'s, page\'s or item\'s content. The match is exact and case-sensitive. A revision is kept.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => Schema::input(
				array(
					'id'      => Schema::id( __( 'The post, page or item ID.', 'zorem-ai-agent-connector' ) ),
					'search'  => array(
						'type'        => 'string',
						'minLength'   => 1,
						'maxLength'   => self::SEARCH_MAX,
						'description' => __( 'The exact text to find, as it appears in the stored content (block markup included).', 'zorem-ai-agent-connector' ),
					),
					'replace' => array(
						'type'        => 'string',
						'maxLength'   => self::REPLACE_MAX,
						'description' => __( 'The text to put in its place. An empty string removes it.', 'zorem-ai-agent-connector' ),
					),
				),
				array( 'id', 'search', 'replace' )
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array( 'type' => 'integer' ),
					'replacements' => array( 'type' => 'integer' ),
					'item'         => array( 'type' => array( 'object', 'null' ) ),
				),
			),
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
		$post  = PostReader::find( absint( $input['id'] ?? 0 ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		$search  = mb_substr( (string) ( $input['search'] ?? '' ), 0, self::SEARCH_MAX );
		$replace = mb_substr( (string) ( $input['replace'] ?? '' ), 0, self::REPLACE_MAX );

		if ( '' === $search ) {
			return new \WP_Error( 'ai_agent_os_empty_search', __( 'The search text is empty.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		$count = substr_count( (string) $post->post_content, $search );

		if ( $count > self::MAX_REPLACEMENTS ) {
			return new \WP_Error(
				'ai_agent_os_too_many_replacements',
				/* translators: 1: matches found, 2: the most allowed */
				sprintf( __( 'The text appears %1$d times; at most %2$d are replaced in one call. Use a longer, more specific search.', 'zorem-ai-agent-connector' ), $count, self::MAX_REPLACEMENTS ),
				array( 'status' => 400 )
			);
		}

		if ( 0 === $count ) {
			return array(
				'id'           => (int) $post->ID,
				'replacements' => 0,
				'item'         => null,
			);
		}

		$saved = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_kses_post( str_replace( $search, $replace, (string) $post->post_content ) ),
				)
			),
			true
		);

		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}

		$fresh = get_post( $post->ID );

		return array(
			'id'           => (int) $post->ID,
			'replacements' => $count,
			'item'         => $fresh instanceof \WP_Post ? PostProjection::summary( $fresh ) : null,
		);
	}
}
