<?php
/**
 * Readme knowledge extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction\Extractors;

use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Extraction\ExtractorInterface;
use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts the useful sections of a WordPress readme.txt.
 *
 * Optional by design. Only nine of the seventeen plugins on the corpus this was
 * built against ship a readme at all, and four of those missing it are Zorem's
 * own — so a readme-first Brain would produce nothing for the plugins the
 * author most wants documented. When it exists it is the best prose available;
 * when it does not, everything else still works.
 *
 * Parsed, not dumped. A readme's changelog is usually the longest section and
 * the least useful to an agent, while the FAQ is often the most useful. Storing
 * the file wholesale would bury one in the other and waste the context window
 * that retrieval is trying to conserve.
 *
 * Everything here is author prose, without exception.
 */
final class ReadmeExtractor implements ExtractorInterface {

	/**
	 * Sections worth keeping, mapped to the knowledge section they belong in.
	 *
	 * @var array<string, string>
	 */
	private const WANTED = array(
		'description'                => KnowledgeEntry::SECTION_OVERVIEW,
		'frequently asked questions' => KnowledgeEntry::SECTION_FAQ,
		'faq'                        => KnowledgeEntry::SECTION_FAQ,
		'installation'               => KnowledgeEntry::SECTION_CONFIGURATION,
		'configuration'              => KnowledgeEntry::SECTION_CONFIGURATION,
		'usage'                      => KnowledgeEntry::SECTION_CONFIGURATION,
		'how it works'               => KnowledgeEntry::SECTION_CONFIGURATION,
	);

	/**
	 * Sections deliberately discarded.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED = array( 'changelog', 'upgrade notice', 'screenshots', 'credits', 'copyright' );

	/**
	 * Characters kept per section.
	 */
	private const MAX_SECTION_CHARS = 8000;

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'readme';
	}

	/**
	 * Primary section written to; this extractor also writes FAQ and configuration.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_FAQ;
	}

	/**
	 * Whether a readme exists.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return null !== $this->readme_path( $context );
	}

	/**
	 * Build entries from the readme's sections.
	 *
	 * @param ExtractionContext $context Plugin context.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( ExtractionContext $context ): array {
		$path = $this->readme_path( $context );

		if ( null === $path ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local plugin file as text.
		$raw = file_get_contents( $path );

		if ( false === $raw || '' === trim( $raw ) ) {
			return array();
		}

		$sections = $this->parse_sections( $raw );
		$grouped  = array();

		foreach ( $sections as $heading => $body ) {
			$key = strtolower( trim( $heading ) );

			if ( in_array( $key, self::IGNORED, true ) || ! isset( self::WANTED[ $key ] ) ) {
				continue;
			}

			$target = self::WANTED[ $key ];

			if ( ! isset( $grouped[ $target ] ) ) {
				$grouped[ $target ] = array();
			}

			$grouped[ $target ][ $heading ] = $body;
		}

		$entries = array();

		foreach ( $grouped as $section => $parts ) {
			$entries[] = $this->build_entry( $context, $section, $parts );
		}

		return $entries;
	}

	/**
	 * Build one entry from a group of readme sections.
	 *
	 * @param ExtractionContext     $context Plugin context.
	 * @param string                $section Knowledge section.
	 * @param array<string, string> $parts   Heading => body.
	 * @return array<string, mixed>
	 */
	private function build_entry( ExtractionContext $context, string $section, array $parts ): array {
		$lines = array();

		foreach ( $parts as $heading => $body ) {
			$lines[] = '## ' . $heading;
			$lines[] = '';
			$lines[] = $body;
			$lines[] = '';
		}

		$content = trim( implode( "\n", $lines ) );

		/*
		 * A readme is documentation, which is to say a claim. The section names
		 * are structural, but the bodies are entirely the author's, and this is
		 * the likeliest place for injection text to reach the Brain.
		 */
		$provenance = Provenance::map(
			array(
				'facts.readme_sections' => Provenance::TOKEN_DERIVED,
				'content'               => Provenance::AUTHOR_PROSE,
				'summary'               => Provenance::AUTHOR_PROSE,
			)
		);

		return array(
			'entry_key'      => KnowledgeEntry::plugin_key( $context->slug(), $section ) . ':readme',
			'source_type'    => KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED,
			'source_ref'     => $context->slug(),
			'plugin_slug'    => $context->slug(),
			'plugin_version' => $context->version(),
			'section'        => $section,
			'title'          => sprintf(
				/* translators: 1: plugin name, 2: section name. */
				__( '%1$s — %2$s', 'zorem-ai-agent-connector' ),
				$context->name(),
				ucfirst( $section )
			),
			'summary'        => wp_trim_words( $content, 40 ),
			'content'        => $content,
			'facts'          => array( 'readme_sections' => array_keys( $parts ) ),
			'meta'           => array_merge(
				$context->base_meta(),
				array( 'provenance' => $provenance )
			),
		);
	}

	/**
	 * Split a readme into heading => body.
	 *
	 * Handles the `== Heading ==` form the WordPress readme standard uses, and
	 * tolerates the `=== Plugin Name ===` title line above it.
	 *
	 * @param string $raw Readme contents.
	 * @return array<string, string>
	 */
	private function parse_sections( string $raw ): array {
		$normalized = str_replace( array( "\r\n", "\r" ), "\n", $raw );

		$parts = preg_split( '/^==\s*(.+?)\s*==\s*$/m', $normalized, -1, PREG_SPLIT_DELIM_CAPTURE );

		if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
			return array();
		}

		$sections = array();
		$count    = count( $parts );

		// $parts[0] is the preamble before the first heading; pairs follow.
		for ( $i = 1; $i < $count; $i += 2 ) {
			$heading = trim( (string) $parts[ $i ] );
			$body    = trim( (string) ( $parts[ $i + 1 ] ?? '' ) );

			if ( '' === $heading || '' === $body ) {
				continue;
			}

			if ( strlen( $body ) > self::MAX_SECTION_CHARS ) {
				$body = substr( $body, 0, self::MAX_SECTION_CHARS ) . "\n\n… (truncated)";
			}

			$sections[ $heading ] = $body;
		}

		return $sections;
	}

	/**
	 * The plugin's readme path, if it has one.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	private function readme_path( ExtractionContext $context ): ?string {
		foreach ( array( 'readme.txt', 'README.txt' ) as $candidate ) {
			$path = $context->directory() . '/' . $candidate;

			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return null;
	}
}
