<?php
/**
 * Static source scanning.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Reads plugin source as tokens to find what a plugin declares.
 *
 * Needed because several things WordPress plugins register leave no runtime
 * trace that can be attributed to them: `register_post_type()` takes no
 * callback, and a `do_action()` the plugin fires for others to hook has no
 * registry at all. Reflection cannot reach either.
 *
 * The absolute rule: **this class reads source, it never runs it.** Everything
 * goes through `token_get_all()`, which lexes a string. There is no `include`,
 * no `require`, no `eval`, no `call_user_func` on anything read from disk. The
 * distinction matters because extraction runs across every active plugin on a
 * site, including whatever a merchant installed last week — and a scanner that
 * executed what it read would be a remote code execution path wearing a
 * documentation badge. `ExtractionSafetyTest` asserts this with a fixture that
 * would write a sentinel file if any of it were executed.
 *
 * A consequence worth stating: text inside comments is captured as prose and
 * tagged `author_prose` (see Provenance), never promoted into a fact. A comment
 * claiming `wp_register_ability( 'store/refund-everything' )` produces no
 * ability fact, because the scanner records calls found in code tokens, and
 * comment tokens are a different thing entirely.
 */
final class TokenScanner {

	/**
	 * Function calls worth recording, and the fact group each belongs to.
	 *
	 * @var array<string, string>
	 */
	private const TRACKED_CALLS = array(
		'register_post_type'  => 'cpts',
		'register_taxonomy'   => 'taxonomies',
		'add_shortcode'       => 'shortcodes',
		'register_setting'    => 'settings',
		'add_options_page'    => 'settings_pages',
		'add_menu_page'       => 'settings_pages',
		'add_submenu_page'    => 'settings_pages',
		'register_rest_route' => 'rest',
		'wp_register_ability' => 'abilities',
		'do_action'           => 'hooks_fired',
		'apply_filters'       => 'hooks_filtered',
	);

	/**
	 * Scan one file.
	 *
	 * @param string $path Absolute file path.
	 * @return array{calls: array<string, array<int, string>>, prose: array<int, string>, error: string|null}
	 */
	public function scan_file( string $path ): array {
		$empty = array(
			'calls' => array(),
			'prose' => array(),
			'error' => null,
		);

		if ( ! is_readable( $path ) ) {
			$empty['error'] = 'unreadable';

			return $empty;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local plugin source as text; WP_Filesystem is for writes and remote paths.
		$source = file_get_contents( $path );

		if ( false === $source ) {
			$empty['error'] = 'unreadable';

			return $empty;
		}

		return $this->scan_source( $source );
	}

	/**
	 * Scan a source string.
	 *
	 * Separated from file reading so tests can feed source directly, and so the
	 * one place that could conceivably execute anything is provably absent from
	 * both paths.
	 *
	 * @param string $source PHP source.
	 * @return array{calls: array<string, array<int, string>>, prose: array<int, string>, error: string|null}
	 */
	public function scan_source( string $source ): array {
		$calls = array();
		$prose = array();

		try {
			/*
			 * TOKEN_PARSE is deliberately NOT passed. With it, PHP evaluates
			 * some constructs to resolve context; without it the call is a pure
			 * lexer. A malformed or hostile file should produce junk tokens,
			 * never behaviour.
			 */
			$tokens = @token_get_all( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A parse warning on third-party source is expected and not actionable.
		} catch ( \Throwable $e ) {
			return array(
				'calls' => array(),
				'prose' => array(),
				'error' => 'unparseable',
			);
		}

		$count = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) ) {
				continue;
			}

			list( $id, $text ) = $token;

			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				$cleaned = $this->clean_comment( $text );

				if ( '' !== $cleaned ) {
					$prose[] = $cleaned;
				}

				continue;
			}

			/*
			 * Everything after __halt_compiler() is data, not code. PHP stops
			 * lexing it, and so do we — reading it as source would be reading
			 * an installer payload or a binary blob as if it were a program.
			 */
			if ( T_HALT_COMPILER === $id ) {
				break;
			}

			if ( T_STRING !== $id ) {
				continue;
			}

			$function = strtolower( $text );

			if ( ! isset( self::TRACKED_CALLS[ $function ] ) ) {
				continue;
			}

			if ( ! $this->is_function_call( $tokens, $i ) ) {
				continue;
			}

			$group     = self::TRACKED_CALLS[ $function ];
			$first_arg = $this->first_string_argument( $tokens, $i, $count );

			if ( null === $first_arg ) {
				continue;
			}

			if ( ! isset( $calls[ $group ] ) ) {
				$calls[ $group ] = array();
			}

			if ( ! in_array( $first_arg, $calls[ $group ], true ) ) {
				$calls[ $group ][] = $first_arg;
			}
		}

		return array(
			'calls' => $calls,
			'prose' => $prose,
			'error' => null,
		);
	}

	/**
	 * Whether a T_STRING at this position is a plain function call.
	 *
	 * Excludes method calls, static calls, declarations and named constants, so
	 * `$this->register_setting(...)` and `function add_shortcode()` do not read
	 * as the global function they resemble.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @param int               $index  Position of the T_STRING.
	 */
	private function is_function_call( array $tokens, int $index ): bool {
		// Look back past whitespace for a disqualifying operator or keyword.
		for ( $i = $index - 1; $i >= 0; $i-- ) {
			$previous = $tokens[ $i ];

			if ( is_array( $previous ) && T_WHITESPACE === $previous[0] ) {
				continue;
			}

			if ( is_array( $previous ) ) {
				if ( in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST ), true ) ) {
					return false;
				}

				if ( defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) && T_NULLSAFE_OBJECT_OPERATOR === $previous[0] ) {
					return false;
				}
			}

			break;
		}

		// Look forward past whitespace for the opening parenthesis.
		$count = count( $tokens );

		for ( $i = $index + 1; $i < $count; $i++ ) {
			$next = $tokens[ $i ];

			if ( is_array( $next ) && T_WHITESPACE === $next[0] ) {
				continue;
			}

			return '(' === $next;
		}

		return false;
	}

	/**
	 * The first literal string argument of a call, when there is one.
	 *
	 * Only literals are recorded. A call whose first argument is a variable or
	 * an expression yields nothing rather than a guess — `register_post_type(
	 * $this->type )` is real evidence that the plugin registers *a* post type,
	 * but not evidence of its name, and inventing one would be worse than the
	 * gap.
	 *
	 * @param array<int, mixed> $tokens Token list.
	 * @param int               $index  Position of the function name.
	 * @param int               $count  Token count.
	 */
	private function first_string_argument( array $tokens, int $index, int $count ): ?string {
		$depth = 0;

		for ( $i = $index + 1; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( is_array( $token ) ) {
				if ( T_WHITESPACE === $token[0] || T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				if ( 1 === $depth && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
					$value = trim( $token[1], "'\"" );

					return '' === $value ? null : $value;
				}

				// A non-literal first argument: record the call, not a name.
				if ( 1 === $depth ) {
					return null;
				}

				continue;
			}

			if ( '(' === $token ) {
				++$depth;
				continue;
			}

			if ( ')' === $token ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Strip comment syntax, leaving the author's text.
	 *
	 * @param string $comment Raw comment token.
	 */
	private function clean_comment( string $comment ): string {
		$text = preg_replace( '#^/\*+|\*+/$|^//+|^\#+#m', '', $comment );
		$text = preg_replace( '#^\s*\*\s?#m', '', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * The calls this scanner tracks, and their fact groups.
	 *
	 * @return array<string, string>
	 */
	public static function tracked_calls(): array {
		return self::TRACKED_CALLS;
	}
}
