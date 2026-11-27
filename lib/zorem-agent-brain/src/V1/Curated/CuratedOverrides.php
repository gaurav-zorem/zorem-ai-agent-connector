<?php
/**
 * Plugins the administrator keeps on this site's own knowledge.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

defined( 'ABSPATH' ) || exit;

/**
 * "Use this site's own knowledge instead" for a plugin the bundled documentation covers.
 *
 * Curated documentation outranks what the Brain reads from a plugin's files,
 * which is right until it is wrong for a particular shop: a heavily customised
 * WooCommerce, say. An overridden plugin is neither loaded from the catalogue
 * nor skipped by local extraction, so it is read from its files like any other.
 * The legacy plugin had this switch; the S2 port dropped it, and S9's migration
 * needs somewhere to carry the merchant's choices.
 */
final class CuratedOverrides {

	/** Option: list of plugin slugs. */
	public const OPTION = 'zorem_agent_brain_curated_overrides';

	/**
	 * Overridden plugin slugs.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) get_option( self::OPTION, array() ) ) ) ) );
	}

	/**
	 * Whether a plugin uses this site's own knowledge.
	 *
	 * @param string $slug Plugin slug.
	 */
	public static function is_local( string $slug ): bool {
		return in_array( sanitize_key( $slug ), self::all(), true );
	}

	/**
	 * Set or clear the override for a plugin.
	 *
	 * @param string $slug      Plugin slug.
	 * @param bool   $use_local Whether to use this site's own knowledge.
	 */
	public static function set( string $slug, bool $use_local ): bool {
		$slug = sanitize_key( $slug );

		if ( '' === $slug ) {
			return false;
		}

		$all = array_values( array_diff( self::all(), array( $slug ) ) );

		if ( $use_local ) {
			$all[] = $slug;
		}

		sort( $all );

		return update_option( self::OPTION, $all, false ) || self::all() === $all;
	}
}
