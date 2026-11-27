<?php
/**
 * A deliberately small YAML front-matter parser.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the front-matter block at the top of a registry entry.
 *
 * Not a YAML parser, and it must never grow into one. The plugin ships no
 * runtime Composer dependencies, so this is ours to maintain, and the surface
 * it accepts is the surface an attacker gets to work with. The grammar is
 * therefore fixed and tiny:
 *
 *   - `key: value` pairs, one per line, at one level. No nesting.
 *   - Keys match `^[a-z][a-z0-9_]*$`.
 *   - Values are scalars: bare, single-quoted, or double-quoted.
 *   - `#` starts a comment when it begins a line.
 *   - No anchors, aliases, tags, multi-line scalars, flow collections or
 *     directives. Each of those is a documented YAML feature and each has been
 *     a deserialisation bug in some parser; none is needed to write a title.
 *
 * Anything outside that grammar is an error, not a best effort. A curated entry
 * that half-parsed would be indistinguishable afterwards from one that parsed
 * correctly, and it would be carrying a human's authority while saying
 * something the human did not write.
 */
final class FrontMatter {

	/**
	 * Longest front-matter block accepted, in bytes.
	 */
	private const MAX_BYTES = 8192;

	/**
	 * Most keys accepted.
	 */
	private const MAX_KEYS = 40;

	/**
	 * Split a document into its front matter and body.
	 *
	 * @param string $source The whole file.
	 * @return array{0: array<string, string>, 1: string}|\WP_Error
	 */
	public static function split( string $source ) {
		// A leading BOM is invisible in an editor and would break the delimiter.
		$source = preg_replace( '/^\xEF\xBB\xBF/', '', $source ) ?? $source;
		$source = str_replace( "\r\n", "\n", $source );

		if ( 0 !== strpos( $source, "---\n" ) ) {
			return new \WP_Error(
				'zorem_agent_brain_registry_no_front_matter',
				__( 'A registry entry must begin with a --- front-matter block.', 'zorem-ai-agent-connector' )
			);
		}

		$end = strpos( $source, "\n---", 3 );

		if ( false === $end ) {
			return new \WP_Error(
				'zorem_agent_brain_registry_unterminated_front_matter',
				__( 'The front-matter block is not closed with ---.', 'zorem-ai-agent-connector' )
			);
		}

		$raw = substr( $source, 4, $end - 3 );

		if ( strlen( $raw ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'zorem_agent_brain_registry_front_matter_too_large',
				__( 'The front-matter block is larger than this parser accepts.', 'zorem-ai-agent-connector' )
			);
		}

		$fields = self::parse( $raw );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$body = substr( $source, $end + 4 );

		return array( $fields, ltrim( (string) $body, "\n" ) );
	}

	/**
	 * Parse the front-matter block itself.
	 *
	 * @param string $raw Front-matter text, without the delimiters.
	 * @return array<string, string>|\WP_Error
	 */
	private static function parse( string $raw ) {
		$fields      = array();
		$line_number = 0;

		foreach ( explode( "\n", $raw ) as $line ) {
			++$line_number;

			$trimmed = trim( $line );

			if ( '' === $trimmed || 0 === strpos( $trimmed, '#' ) ) {
				continue;
			}

			/*
			 * Indentation would mean nesting, and nesting is not in the grammar.
			 * Rejecting it is better than flattening it: a curator who indented
			 * something meant it to be structured, and silently reading it as a
			 * top-level key would store something they did not intend.
			 */
			if ( ltrim( $line ) !== $line ) {
				return self::invalid( $line_number, __( 'indented lines are not supported', 'zorem-ai-agent-connector' ) );
			}

			$position = strpos( $trimmed, ':' );

			if ( false === $position || 0 === $position ) {
				return self::invalid( $line_number, __( 'expected "key: value"', 'zorem-ai-agent-connector' ) );
			}

			$key = substr( $trimmed, 0, $position );

			if ( ! preg_match( '/^[a-z][a-z0-9_]*$/', $key ) ) {
				return self::invalid( $line_number, __( 'key must be lowercase letters, digits and underscores', 'zorem-ai-agent-connector' ) );
			}

			if ( isset( $fields[ $key ] ) ) {
				return self::invalid( $line_number, __( 'duplicate key', 'zorem-ai-agent-connector' ) );
			}

			if ( count( $fields ) >= self::MAX_KEYS ) {
				return self::invalid( $line_number, __( 'too many keys', 'zorem-ai-agent-connector' ) );
			}

			$value = self::scalar( trim( substr( $trimmed, $position + 1 ) ) );

			if ( is_wp_error( $value ) ) {
				return self::invalid( $line_number, $value->get_error_message() );
			}

			$fields[ $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Read one scalar value.
	 *
	 * @param string $value Raw value text.
	 * @return string|\WP_Error
	 */
	private static function scalar( string $value ) {
		if ( '' === $value ) {
			return '';
		}

		$first = $value[0];

		if ( '"' === $first || "'" === $first ) {
			if ( strlen( $value ) < 2 || substr( $value, -1 ) !== $first ) {
				return new \WP_Error( 'unterminated', __( 'unterminated quoted value', 'zorem-ai-agent-connector' ) );
			}

			$inner = substr( $value, 1, -1 );

			/*
			 * No escape processing. A quoted value is taken literally, so there
			 * is no sequence a curator can write that changes how the rest of
			 * the file is read.
			 */
			if ( false !== strpos( $inner, $first ) ) {
				return new \WP_Error( 'quote', __( 'quoted values may not contain their own quote character', 'zorem-ai-agent-connector' ) );
			}

			return $inner;
		}

		/*
		 * Bare values reject the characters that begin YAML's structural
		 * features — collections, anchors, aliases, tags, directives. A title
		 * needing one of those can be quoted.
		 */
		if ( preg_match( '/^[\[\{\&\*\!\|\>\%\@\`]/', $value ) ) {
			return new \WP_Error( 'structure', __( 'unsupported value; quote it if the text is meant literally', 'zorem-ai-agent-connector' ) );
		}

		return $value;
	}

	/**
	 * An error naming the line.
	 *
	 * @param int    $line   Line number within the block.
	 * @param string $reason What was wrong.
	 */
	private static function invalid( int $line, string $reason ): \WP_Error {
		return new \WP_Error(
			'zorem_agent_brain_registry_invalid_front_matter',
			sprintf(
				/* translators: 1: line number, 2: reason. */
				__( 'Front matter line %1$d: %2$s.', 'zorem-ai-agent-connector' ),
				$line,
				$reason
			)
		);
	}
}
