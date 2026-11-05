<?php
/**
 * Fencing untrusted text before it reaches a model.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps untrusted text in a delimited block a model is told to treat as quoted.
 *
 * Ported from the legacy plugin's PromptBuilder::fenced(). Prompts must name
 * the delimiter through Fence::DELIMITER, never a literal. One implementation
 * for every prompt in every Agent OS component: two implementations of a
 * boundary drift, and the one that drifts is the one nobody remembered.
 *
 * Each block carries a random id beside the delimiter, at both ends, so text
 * that guesses the delimiter still cannot close a block: it would also have
 * to guess this call's id. Inside the text, the delimiter's name is removed
 * however it is spelled — any case, with zero-width characters inside, or
 * with lookalike letters from other alphabets.
 */
final class Fence {

	/** The delimiter. It appears in a prompt only where wrap() put it. */
	public const DELIMITER = '<<<ZOREM-UNTRUSTED-TEXT>>>';

	/** What an occurrence of the delimiter inside untrusted text becomes. */
	public const REMOVED = '[delimiter removed]';

	/** The delimiter's name, as compared once lookalikes are folded. */
	private const NAME = 'zorem-untrusted-text';

	/** Characters that render as nothing. */
	private const INVISIBLE = '/[\x{00AD}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

	/**
	 * Letters from other alphabets that look like the delimiter's, folded to ASCII.
	 */
	private const LOOKALIKES = array(
		'А' => 'a',
		'В' => 'b',
		'Е' => 'e',
		'К' => 'k',
		'М' => 'm',
		'Н' => 'h',
		'О' => 'o',
		'Р' => 'p',
		'С' => 'c',
		'Т' => 't',
		'Х' => 'x',
		'а' => 'a',
		'е' => 'e',
		'о' => 'o',
		'р' => 'p',
		'с' => 'c',
		'т' => 't',
		'х' => 'x',
		'Α' => 'a',
		'Β' => 'b',
		'Ε' => 'e',
		'Ζ' => 'z',
		'Η' => 'h',
		'Ι' => 'i',
		'Κ' => 'k',
		'Μ' => 'm',
		'Ν' => 'n',
		'Ο' => 'o',
		'Ρ' => 'p',
		'Τ' => 't',
		'Χ' => 'x',
		'Υ' => 'y',
		'ο' => 'o',
		'ε' => 'e',
		'‐' => '-',
		'‑' => '-',
		'‒' => '-',
		'–' => '-',
		'—' => '-',
		'−' => '-',
		'_' => '-',
		' ' => '-',
	);

	/**
	 * Wrap untrusted text.
	 *
	 * Any occurrence of the delimiter inside the text is replaced, not escaped,
	 * so the text cannot close its own block early.
	 *
	 * @param string $text Untrusted text.
	 */
	public static function wrap( string $text ): string {
		$id = self::block_id();

		return self::DELIMITER . ' ' . $id . "\n" . self::strip( $text ) . "\n" . $id . ' ' . self::DELIMITER;
	}

	/**
	 * Flatten a value used in a heading: no newlines, no delimiter.
	 *
	 * @param string $value Raw value.
	 */
	public static function plain( string $value ): string {
		return trim( str_replace( array( "\r", "\n" ), ' ', self::strip( $value ) ) );
	}

	/**
	 * Remove the delimiter from text, however it is disguised.
	 *
	 * @param string $text Text.
	 */
	public static function strip( string $text ): string {
		$text = str_replace( self::DELIMITER, self::REMOVED, $text );

		if ( class_exists( \Normalizer::class ) ) {
			$normal = \Normalizer::normalize( $text, \Normalizer::FORM_KC );
			$text   = is_string( $normal ) ? $normal : $text;
		}

		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );

		if ( false === $chars ) {
			return str_ireplace( self::NAME, self::REMOVED, $text );
		}

		// Fold each character, and remember which character each folded one came from.
		$folded = '';
		$origin = array();

		foreach ( $chars as $index => $char ) {
			if ( 1 === preg_match( self::INVISIBLE, $char ) ) {
				continue;
			}

			$letter   = self::LOOKALIKES[ $char ] ?? ( strlen( $char ) === 1 ? strtolower( $char ) : '?' );
			$folded  .= $letter;
			$origin[] = $index;
		}

		$length = strlen( self::NAME );
		$offset = 0;
		$cut    = array();

		$found = strpos( $folded, self::NAME, $offset );

		while ( false !== $found ) {
			$cut[ $origin[ $found ] ] = $origin[ $found + $length - 1 ];
			$offset                   = $found + $length;
			$found                    = strpos( $folded, self::NAME, $offset );
		}

		if ( array() === $cut ) {
			return implode( '', $chars );
		}

		$out = '';

		for ( $i = 0, $n = count( $chars ); $i < $n; $i++ ) {
			if ( isset( $cut[ $i ] ) ) {
				$out .= self::REMOVED;
				$i    = $cut[ $i ];
				continue;
			}

			$out .= $chars[ $i ];
		}

		return $out;
	}

	/**
	 * A random id for one block.
	 */
	private static function block_id(): string {
		try {
			return bin2hex( random_bytes( 6 ) );
		} catch ( \Exception $e ) {
			return substr( md5( uniqid( '', true ) ), 0, 12 );
		}
	}
}
