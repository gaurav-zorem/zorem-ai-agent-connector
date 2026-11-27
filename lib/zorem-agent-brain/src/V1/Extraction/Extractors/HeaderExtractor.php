<?php
/**
 * Plugin header knowledge extraction.
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
 * Documents what a plugin is, from its header.
 *
 * The only extractor that produces something for every plugin, which is why it
 * runs first. It is also almost entirely author prose: a plugin header is a
 * set of strings someone typed, and the sole verified facts in it are the
 * version and the file's existence.
 */
final class HeaderExtractor implements ExtractorInterface {

	/**
	 * Identifier.
	 */
	public function get_id(): string {
		return 'header';
	}

	/**
	 * Section written to.
	 */
	public function get_section(): string {
		return KnowledgeEntry::SECTION_OVERVIEW;
	}

	/**
	 * Every plugin has a header.
	 *
	 * @param ExtractionContext $context Plugin context.
	 */
	public function supports( ExtractionContext $context ): bool {
		return array() !== $context->header();
	}

	/**
	 * Build the overview entry.
	 *
	 * @param ExtractionContext $context Plugin context.
	 * @return array<int, array<string, mixed>>
	 */
	public function extract( ExtractionContext $context ): array {
		$name        = $context->name();
		$description = $context->header_field( 'Description' );
		$version     = $context->version();

		$facts = array(
			'name'         => $name,
			'slug'         => $context->slug(),
			'version'      => $version,
			'author'       => wp_strip_all_tags( $context->header_field( 'Author' ) ),
			'requires_wp'  => $context->header_field( 'RequiresWP' ),
			'requires_php' => $context->header_field( 'RequiresPHP' ),
			'text_domain'  => $context->header_field( 'TextDomain' ),
			'network'      => (bool) ( $context->header()['Network'] ?? false ),
		);

		$lines = array( '# ' . $name );

		if ( '' !== $description ) {
			$lines[] = '';
			$lines[] = wp_strip_all_tags( $description );
		}

		$lines[] = '';
		$lines[] = '## Installed';
		$lines[] = '';
		$lines[] = '- Version: ' . ( '' === $version ? 'unknown' : $version );
		$lines[] = '- Slug: `' . $context->slug() . '`';

		if ( '' !== $facts['author'] ) {
			$lines[] = '- Author: ' . $facts['author'];
		}

		if ( '' !== $facts['requires_wp'] ) {
			$lines[] = '- Requires WordPress: ' . $facts['requires_wp'];
		}

		if ( '' !== $facts['requires_php'] ) {
			$lines[] = '- Requires PHP: ' . $facts['requires_php'];
		}

		$truncated = $context->truncated();

		if ( null !== $truncated ) {
			$lines[] = '';
			$lines[] = '> Knowledge for this plugin is incomplete: the source scan stopped early ('
				. $truncated . '). Facts derived from scanning may be missing.';
		}

		return array(
			array(
				'entry_key'      => KnowledgeEntry::plugin_key( $context->slug(), $this->get_section() ),
				'source_type'    => KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED,
				'source_ref'     => $context->slug(),
				'plugin_slug'    => $context->slug(),
				'plugin_version' => $version,
				'section'        => $this->get_section(),
				'title'          => $name,
				'summary'        => '' === $description
					? sprintf(
						/* translators: %s: plugin name. */
						__( 'Installed plugin: %s.', 'zorem-ai-agent-connector' ),
						$name
					)
					: wp_trim_words( wp_strip_all_tags( $description ), 40 ),
				'content'        => implode( "\n", $lines ),
				'facts'          => $facts,
				'meta'           => array_merge(
					$context->base_meta(),
					array(

						/*
						 * A plugin header is strings an author typed. Only the
						 * version is used for anything mechanical, and even that
						 * is self-reported.
						 */
						'provenance' => Provenance::map(
							array(
								'facts'   => Provenance::AUTHOR_PROSE,
								'content' => Provenance::AUTHOR_PROSE,
								'summary' => Provenance::AUTHOR_PROSE,
							)
						),
					)
				),
			),
		);
	}
}
