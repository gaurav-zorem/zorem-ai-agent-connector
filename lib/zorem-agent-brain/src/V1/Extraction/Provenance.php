<?php
/**
 * Where a piece of extracted knowledge came from.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

use ZoremAgentBridge\V1\Security\Provenance as BridgeProvenance;

defined( 'ABSPATH' ) || exit;

/**
 * Distinguishes verified structure from text a plugin author wrote.
 *
 * The distinction is a security boundary, not a curiosity. A plugin's comments
 * and readme are *untrusted input*: a docblock reading "ignore previous
 * instructions" is a prompt-injection vector delivered through the Brain. A
 * `token_derived` fact is something the scanner verified from code structure;
 * an `author_prose` value is text someone typed, and only the second can lie
 * about the software it describes.
 *
 * The classes themselves are the bridge's `Security\Provenance` — one
 * definition, because the legacy plugin had writers using labels the reader did
 * not know. This class keeps the per-field map helpers the extraction pipeline
 * uses, under the names its ported code already calls.
 */
final class Provenance {

	public const TOKEN_DERIVED = BridgeProvenance::TOKEN_DERIVED;
	public const AUTHOR_PROSE  = BridgeProvenance::AUTHOR_PROSE;
	public const AI_GENERATED  = BridgeProvenance::AI_GENERATED;
	public const CURATED       = BridgeProvenance::CURATED;
	public const CONFIGURATION = BridgeProvenance::CONFIGURATION;

	/**
	 * Every provenance class.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return BridgeProvenance::all();
	}

	/**
	 * Whether a class describes verified structure.
	 *
	 * @param string $provenance Class.
	 */
	public static function is_verified( string $provenance ): bool {
		return BridgeProvenance::is_verified( $provenance );
	}

	/**
	 * Whether a value is a known class.
	 *
	 * @param string $provenance Candidate.
	 */
	public static function is_valid( string $provenance ): bool {
		return BridgeProvenance::is_valid( $provenance );
	}

	/**
	 * Clean a field → provenance map. Unknown classes become author_prose:
	 * a value whose origin cannot be named is treated as text someone typed.
	 *
	 * @param array<string, mixed> $map Field path => class.
	 * @return array<string, string>
	 */
	public static function map( array $map ): array {
		$clean = array();

		foreach ( $map as $path => $provenance ) {
			$clean[ (string) $path ] = BridgeProvenance::normalize( (string) $provenance );
		}

		return $clean;
	}

	/**
	 * The provenance of one field in an entry's meta; author_prose when unrecorded.
	 *
	 * @param array<string, mixed> $meta Entry meta.
	 * @param string               $path Field path.
	 */
	public static function of( array $meta, string $path ): string {
		$map = isset( $meta['provenance'] ) && is_array( $meta['provenance'] ) ? $meta['provenance'] : array();

		return BridgeProvenance::normalize( isset( $map[ $path ] ) ? (string) $map[ $path ] : '' );
	}
}
