<?php
/**
 * Knowledge section sensitivity.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Scope;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies how sensitive a knowledge section is.
 *
 * Ported from the legacy plugin. A section describing *abilities* tells a
 * reader about tools it already holds — no escalation. Sections describing
 * configuration, hooks, REST routes and post types describe the site's
 * internal shape: reconnaissance material. Public sections derive from a grant;
 * internal sections additionally require a reader that acts for an
 * administrator, or an explicit scope rule. Anything unclassified is internal.
 *
 * The legacy plugin decided "internal" from the agent's *type* and a
 * storefront flag, and its row check and SQL filter once asked different
 * questions. Here there is one question — Reader::reads_internal(), which
 * reads `acts_for` — and both paths ask it.
 *
 * Every public section is enumerable — public_sections() lists them all —
 * because the SQL filter needs the list, not a callback. That is why a custom
 * section must be *declared* before the tier filter can open it: a section the
 * filter called public but nothing declared would be allowed by the row check
 * and missing from the query, so a search would silently disagree with a read.
 */
final class SectionTiers {

	public const TIER_PUBLIC   = 'public';
	public const TIER_INTERNAL = 'internal';

	/**
	 * Sections readable on the strength of a grant alone.
	 *
	 * @var array<int, string>
	 */
	private const PUBLIC_SECTIONS = array(
		KnowledgeEntry::SECTION_OVERVIEW,
		KnowledgeEntry::SECTION_ABILITIES,
		KnowledgeEntry::SECTION_FAQ,
		KnowledgeEntry::SECTION_SHORTCODES,
		KnowledgeEntry::SECTION_MANUAL,
		KnowledgeEntry::SECTION_DOCUMENT,
	);

	/**
	 * Built-in sections describing the site's internal shape.
	 *
	 * @var array<int, string>
	 */
	private const INTERNAL_SECTIONS = array(
		KnowledgeEntry::SECTION_CONFIGURATION,
		KnowledgeEntry::SECTION_HOOKS,
		KnowledgeEntry::SECTION_REST,
		KnowledgeEntry::SECTION_CPTS,
	);

	/**
	 * The tier of a section.
	 *
	 * The filter may close a built-in public section or open a declared custom
	 * one. It cannot open a built-in internal section, nor an undeclared one,
	 * and anything that does not resolve to 'public' is internal.
	 *
	 * @param string $section Section name.
	 */
	public static function tier( string $section ): string {
		if ( in_array( $section, self::INTERNAL_SECTIONS, true ) ) {
			return self::TIER_INTERNAL;
		}

		$built_in = in_array( $section, self::PUBLIC_SECTIONS, true );

		if ( ! $built_in && ! in_array( $section, self::custom_sections(), true ) ) {
			return self::TIER_INTERNAL;
		}

		/**
		 * Filters a knowledge section's sensitivity tier.
		 *
		 * Called for built-in public sections and for custom sections declared
		 * through `zorem_agent_brain_custom_sections`. Built-in internal
		 * sections never reach it.
		 *
		 * @param string $tier    public or internal.
		 * @param string $section The section name.
		 */
		$filtered = apply_filters( 'zorem_agent_brain_section_tier', $built_in ? self::TIER_PUBLIC : self::TIER_INTERNAL, $section );

		return self::TIER_PUBLIC === $filtered ? self::TIER_PUBLIC : self::TIER_INTERNAL;
	}

	/**
	 * Whether a section is readable without an explicit rule.
	 *
	 * @param string $section Section name.
	 */
	public static function is_public( string $section ): bool {
		return self::TIER_PUBLIC === self::tier( $section );
	}

	/**
	 * Every public section, built-in and declared.
	 *
	 * @return array<int, string>
	 */
	public static function public_sections(): array {
		return array_values( array_filter( self::all_sections(), array( self::class, 'is_public' ) ) );
	}

	/**
	 * Every known section: built-in, then declared custom ones.
	 *
	 * @return array<int, string>
	 */
	public static function all_sections(): array {
		return array_values( array_unique( array_merge( self::PUBLIC_SECTIONS, self::INTERNAL_SECTIONS, self::custom_sections() ) ) );
	}

	/**
	 * Custom sections declared by plugins.
	 *
	 * @return array<int, string>
	 */
	private static function custom_sections(): array {
		/**
		 * Declares custom knowledge sections, so the tier filter may classify them.
		 *
		 * @param array<int, string> $sections Section names.
		 */
		$declared = (array) apply_filters( 'zorem_agent_brain_custom_sections', array() );
		$out      = array();

		foreach ( $declared as $section ) {
			if ( is_string( $section ) && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $section ) ) {
				$out[] = $section;
			}
		}

		return $out;
	}
}
