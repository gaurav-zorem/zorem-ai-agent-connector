<?php
/**
 * The `ai-agent-os/refresh-cache` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Purges the page cache for one item, or for the whole site.
 *
 * Labelled "Clear cache", named `refresh-cache`: RiskClassifier rates any
 * ability whose name says clear, purge or flush as destructive, and that
 * floor is right for abilities it knows nothing about. Purging a page cache
 * loses nothing (the pages are rebuilt on the next visit), so this is a
 * write, and the name says what it does instead of tripping the floor.
 *
 * Writes that change meta directly (update-post-meta) do not fire the hooks
 * cache plugins listen for, so visitors keep seeing the old page until it
 * is purged. This asks each supported cache plugin to purge, through that
 * plugin's own public function or action, and says which ones it reached:
 * "no cache plugin found" is an answer, not a silent success (PATTERNS 5).
 *
 * The whole-site purge needs `edit_others_posts` (an editor's reach): it
 * slows the site for a while and touches everyone's pages.
 */
final class RefreshCache {

	/** Ability name. */
	public const NAME = 'ai-agent-os/refresh-cache';

	/** Capabilities lent for the call. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Clear the site cache', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Purges the page cache (WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache or WP Fastest Cache) for one item, or for the whole site when no ID is given. Use it after changing custom fields, which cache plugins do not notice.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The post, page or item whose cache to purge. Leave out to purge the whole site, which briefly slows it down.', 'zorem-ai-agent-connector' ) ) )
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'scope'  => array(
						'type' => 'string',
						'enum' => array( 'item', 'site' ),
					),
					'id'     => array( 'type' => array( 'integer', 'null' ) ),
					'purged' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),

			/*
			 * Two scopes, two bars, the same ones `execute()` enforces: purging
			 * one item needs edit access to that item, and purging the whole
			 * site — which briefly slows it down for everyone — needs the
			 * capability that marks someone as more than an author of their own
			 * posts.
			 */
			'permission_callback' => static function ( $input = null ): bool {
				return is_array( $input ) && isset( $input['id'] )
					? Access::can_edit_post( $input )
					: current_user_can( 'edit_others_posts' );
			},
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

		if ( ! isset( $input['id'] ) ) {
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				return PostWriter::forbidden();
			}

			return array(
				'scope'  => 'site',
				'id'     => null,
				'purged' => self::purge( 0 ),
			);
		}

		$post = PostReader::find( absint( $input['id'] ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		clean_post_cache( $post->ID );

		return array(
			'scope'  => 'item',
			'id'     => (int) $post->ID,
			'purged' => self::purge( (int) $post->ID ),
		);
	}

	/**
	 * Ask every active cache plugin to purge.
	 *
	 * @param int $post_id An item ID, or 0 for the whole site.
	 * @return array<int, string> The cache plugins reached.
	 */
	private static function purge( int $post_id ): array {
		$purged = array();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Other plugins' own purge actions.
		if ( function_exists( 'rocket_clean_post' ) && function_exists( 'rocket_clean_domain' ) ) {
			$post_id > 0 ? rocket_clean_post( $post_id ) : rocket_clean_domain();
			$purged[] = 'wp-rocket';
		}

		if ( defined( 'LSCWP_V' ) ) {
			$post_id > 0 ? do_action( 'litespeed_purge_post', $post_id ) : do_action( 'litespeed_purge_all' );
			$purged[] = 'litespeed-cache';
		}

		if ( function_exists( 'w3tc_flush_post' ) && function_exists( 'w3tc_flush_all' ) ) {
			$post_id > 0 ? w3tc_flush_post( $post_id ) : w3tc_flush_all();
			$purged[] = 'w3-total-cache';
		}

		if ( function_exists( 'wp_cache_post_change' ) && function_exists( 'wp_cache_clear_cache' ) ) {
			$post_id > 0 ? wp_cache_post_change( $post_id ) : wp_cache_clear_cache();
			$purged[] = 'wp-super-cache';
		}

		if ( class_exists( 'WpFastestCache' ) ) {
			$post_id > 0 ? do_action( 'wpfc_clear_post_cache_by_id', false, $post_id ) : do_action( 'wpfc_clear_all_cache' );
			$purged[] = 'wp-fastest-cache';
		}
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		return $purged;
	}
}
