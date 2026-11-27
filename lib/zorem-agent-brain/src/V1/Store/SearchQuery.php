<?php
/**
 * A search string supplied by an agent.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises a search string that arrived from a model.
 *
 * The query text on this path comes from an AI agent, which means it comes from
 * whoever last talked to that agent. It is untrusted input in the ordinary
 * sense — bind it, bound it, escape it — and untrusted in a second sense as
 * well: an agent can be argued into searching for whatever an attacker wants,
 * so the search itself must not be usable as a probe.
 *
 * Three decisions, all deliberate:
 *
 * **Boolean-mode operators are not honoured.** MySQL's `IN BOOLEAN MODE`
 * interprets `+`, `-`, `*`, `<`, `>`, `~`, `"` and parentheses. A `-` exclusion
 * or a `*` wildcard turns search into a probe: an agent that cannot read
 * WooCommerce's configuration could otherwise narrow down what is in it by
 * watching which prefixes change the result count. Searches therefore run in
 * natural-language mode, where those characters are just characters. They are
 * stripped anyway, so a query containing them degrades to its plain words
 * rather than silently matching nothing.
 *
 * **Length is capped.** A megabyte-long query is not a search, and both the
 * FULLTEXT parser and a `LIKE '%…%'` scan get expensive in the length of the
 * needle. The cap is applied before anything else touches the string.
 *
 * **The empty result is explicit.** A query that normalises to nothing returns
 * `is_empty()`, and callers refuse rather than running an unbounded match.
 */
final class SearchQuery {

	/**
	 * Longest query accepted, in characters.
	 *
	 * Generous for a real search and far below anything that would strain the
	 * parser. Longer input is truncated rather than rejected: an agent that
	 * pasted a paragraph meant to search for something.
	 */
	public const MAX_LENGTH = 200;

	/**
	 * Shortest term worth searching for.
	 */
	public const MIN_TERM_LENGTH = 2;

	/**
	 * The normalised query.
	 *
	 * @var string
	 */
	private string $normalized;

	/**
	 * The query as supplied, after length capping only.
	 *
	 * @var string
	 */
	private string $raw;

	/**
	 * Constructor.
	 *
	 * @param string $raw Query text as supplied by the agent.
	 */
	public function __construct( string $raw ) {
		// Cap first: everything below is cheaper on a bounded string.
		$capped = mb_substr( trim( $raw ), 0, self::MAX_LENGTH );

		$this->raw        = $capped;
		$this->normalized = $this->normalize( $capped );
	}

	/**
	 * Whether there is nothing worth searching for.
	 */
	public function is_empty(): bool {
		return '' === $this->normalized;
	}

	/**
	 * The normalised query, safe to bind into a FULLTEXT match.
	 */
	public function normalized(): string {
		return $this->normalized;
	}

	/**
	 * The query as supplied, capped. For logging only.
	 */
	public function raw(): string {
		return $this->raw;
	}

	/**
	 * The individual terms, for the LIKE fallback.
	 *
	 * @return array<int, string>
	 */
	public function terms(): array {
		if ( $this->is_empty() ) {
			return array();
		}

		$terms = array();

		$pieces = preg_split( '/\s+/', $this->normalized );

		foreach ( is_array( $pieces ) ? $pieces : array() as $term ) {
			$term = (string) $term;

			if ( mb_strlen( $term ) >= self::MIN_TERM_LENGTH && ! in_array( $term, $terms, true ) ) {
				$terms[] = $term;
			}
		}

		// A handful is plenty; each one costs a LIKE scan.
		return array_slice( $terms, 0, 6 );
	}

	/**
	 * Strip everything that is an operator rather than a word.
	 *
	 * @param string $raw Capped query text.
	 */
	private function normalize( string $raw ): string {
		/*
		 * Keep letters, digits, marks, spaces and the few punctuation marks that
		 * appear inside real identifiers an agent would search for — hyphens,
		 * underscores, slashes and dots, as in `woocommerce/products-query` or
		 * `wc_get_order`. Everything else, including every boolean-mode
		 * operator, becomes a space.
		 */
		$cleaned = preg_replace( '/[^\p{L}\p{N}\p{M}\s_\-\/\.]+/u', ' ', $raw );

		if ( ! is_string( $cleaned ) ) {
			// A malformed UTF-8 sequence makes preg return null. Treat as empty.
			return '';
		}

		$kept = array();

		$pieces = preg_split( '/\s+/u', trim( $cleaned ) );

		foreach ( is_array( $pieces ) ? $pieces : array() as $piece ) {
			$piece = (string) $piece;

			/*
			 * A run of punctuation is not a word. `---` survives the character
			 * filter because hyphens appear inside real identifiers, but on its
			 * own it is an operator someone typed, and searching for it would
			 * scan every row to match nothing.
			 */
			if ( '' !== $piece && preg_match( '/[\p{L}\p{N}]/u', $piece ) ) {
				$kept[] = $piece;
			}
		}

		return implode( ' ', $kept );
	}
}
