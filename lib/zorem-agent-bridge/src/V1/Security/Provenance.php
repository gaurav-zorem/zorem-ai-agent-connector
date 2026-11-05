<?php
/**
 * Where a piece of text came from.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The provenance vocabulary, shared by every Agent OS component.
 *
 * Provenance is a boundary, not metadata (PATTERNS 11). Text observed from
 * code can reach a model as fact; everything else — plugin authors' prose,
 * merchant pages, model output, curated entries, store settings with typed
 * names in them — reaches a model fenced as quoted material. One definition,
 * because the legacy plugin had writers using labels the reader did not know.
 */
final class Provenance {

	/** Verified from code structure or the runtime registry. */
	public const TOKEN_DERIVED = 'token_derived';

	/** Text a third party or the merchant typed. */
	public const AUTHOR_PROSE = 'author_prose';

	/** Written by a model; reviewed before any agent reads it. */
	public const AI_GENERATED = 'ai_generated';

	/** Hand-written and reviewed before release. */
	public const CURATED = 'curated';

	/** Read from settings rows; may contain typed names and addresses. */
	public const CONFIGURATION = 'configuration';

	/**
	 * Every provenance class.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::TOKEN_DERIVED, self::AUTHOR_PROSE, self::AI_GENERATED, self::CURATED, self::CONFIGURATION );
	}

	/**
	 * Whether a value is a known class.
	 *
	 * @param string $provenance Candidate.
	 */
	public static function is_valid( string $provenance ): bool {
		return in_array( $provenance, self::all(), true );
	}

	/**
	 * A known class, or author_prose for anything unknown.
	 *
	 * Unknown fails toward "untrusted": if we did not record how something was
	 * derived, we cannot claim it was verified.
	 *
	 * @param string $provenance Candidate.
	 */
	public static function normalize( string $provenance ): string {
		return self::is_valid( $provenance ) ? $provenance : self::AUTHOR_PROSE;
	}

	/**
	 * Whether text of this provenance may reach a model unfenced.
	 *
	 * Only what was observed from code. A class added to all() later is fenced
	 * until someone decides otherwise here, in the one place that decides it.
	 *
	 * @param string $provenance Provenance class.
	 */
	public static function is_verified( string $provenance ): bool {
		return self::TOKEN_DERIVED === $provenance;
	}
}
