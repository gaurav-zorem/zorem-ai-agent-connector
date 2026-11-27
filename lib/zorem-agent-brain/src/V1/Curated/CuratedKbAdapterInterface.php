<?php
/**
 * Curated knowledge source contract.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

defined( 'ABSPATH' ) || exit;

/**
 * A source of hand-written knowledge about a plugin.
 *
 * Implementations must treat every failure as "no coverage". A curated source
 * that is unreachable, slow, or returning nonsense must leave the site exactly
 * as it would be if the source did not exist — extracting locally, serving what
 * it has. A down CDN is not allowed to degrade a merchant's store, and an
 * adapter that reported an error upward would eventually find a caller that
 * treated the error as an answer.
 */
interface CuratedKbAdapterInterface {

	/**
	 * Whether this source covers a plugin at a given version.
	 *
	 * @param string $plugin_slug    Plugin slug.
	 * @param string $plugin_version Installed version.
	 */
	public function supports( string $plugin_slug, string $plugin_version ): bool;

	/**
	 * The entries this source has for a plugin.
	 *
	 * @param string $plugin_slug    Plugin slug.
	 * @param string $plugin_version Installed version.
	 * @return array<int, array<string, mixed>>|\WP_Error Entry payloads.
	 */
	public function fetch( string $plugin_slug, string $plugin_version );

	/**
	 * A stable identifier for this source, used in entry keys and the UI.
	 */
	public function get_source_id(): string;

	/**
	 * Which of the offered slugs this source covers.
	 *
	 * @param array<int, string> $slugs Candidate slugs.
	 * @return array<int, string>
	 */
	public function covered_slugs( array $slugs ): array;
}
