<?php
/**
 * Turns a registry .md file into a knowledge entry payload.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Scope\SectionTiers;

defined( 'ABSPATH' ) || exit;

/**
 * Reads one curated entry.
 *
 * The format exists for the humans who write and review it — front matter for
 * the fields, Markdown for the prose, a fenced JSON block for machine-readable
 * facts. Agents never see it; they read the `content` column through MCP, which
 * is already Markdown. Optimising the file for the reader who actually opens it.
 *
 * **Rejection is whole-entry, always.** There is no partial ingestion and no
 * best-effort repair. A curated entry carries a human's authority, and one that
 * was half-read would be indistinguishable afterwards from one that was written
 * that way — the reason is logged, and nothing is stored.
 */
final class RegistryParser {

	/**
	 * Largest entry file accepted, in bytes.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * Fields a registry entry must carry.
	 *
	 * @var array<int, string>
	 */
	private const REQUIRED = array( 'entry_key', 'section', 'title' );

	/**
	 * Parse one entry.
	 *
	 * @param string $source      File contents.
	 * @param string $plugin_slug Plugin the entry belongs to.
	 * @param string $source_id   Identifier of the catalog it came from.
	 * @return array<string, mixed>|\WP_Error Entry payload for the repository.
	 */
	public function parse( string $source, string $plugin_slug, string $source_id = 'curated' ) {
		if ( strlen( $source ) > self::MAX_BYTES ) {
			return new \WP_Error(
				'zorem_agent_brain_registry_entry_too_large',
				__( 'The registry entry is larger than this parser accepts.', 'zorem-ai-agent-connector' )
			);
		}

		$split = FrontMatter::split( $source );

		if ( is_wp_error( $split ) ) {
			return $split;
		}

		list( $fields, $body ) = $split;

		foreach ( self::REQUIRED as $required ) {
			if ( '' === (string) ( $fields[ $required ] ?? '' ) ) {
				return new \WP_Error(
					'zorem_agent_brain_registry_missing_field',
					sprintf(
						/* translators: %s: field name. */
						__( 'The registry entry is missing the required field "%s".', 'zorem-ai-agent-connector' ),
						$required
					)
				);
			}
		}

		$section = (string) $fields['section'];

		if ( ! in_array( $section, SectionTiers::all_sections(), true ) ) {
			/*
			 * An unknown section would be classified internal by SectionTiers'
			 * fail-closed rule and become unreadable, which looks like the
			 * curation failed rather than like the file was wrong. Better to say
			 * so at parse time.
			 */
			return new \WP_Error(
				'zorem_agent_brain_registry_unknown_section',
				sprintf(
					/* translators: %s: section name. */
					__( 'The registry entry declares an unknown section "%s".', 'zorem-ai-agent-connector' ),
					$section
				)
			);
		}

		$facts = $this->extract_facts( $body );

		if ( is_wp_error( $facts ) ) {
			return $facts;
		}

		$logical_key = (string) $fields['entry_key'];

		return array(
			'entry_key'      => self::storage_key( $source_id, $logical_key ),
			'source_type'    => KnowledgeEntry::SOURCE_REMOTE_CURATED,
			'source_ref'     => $source_id,
			'plugin_slug'    => $plugin_slug,
			'plugin_version' => (string) ( $fields['plugin_version'] ?? '' ),
			'section'        => $section,
			'title'          => (string) $fields['title'],
			'summary'        => (string) ( $fields['summary'] ?? $this->first_paragraph( $body ) ),
			'content'        => $body,
			'facts'          => $facts,
			'trust_status'   => KnowledgeEntry::TRUST_APPROVED,
			'priority'       => isset( $fields['priority'] )
				? (int) $fields['priority']
				: KnowledgeEntry::default_priority( KnowledgeEntry::SOURCE_REMOTE_CURATED ),
			'checksum'       => (string) ( $fields['checksum'] ?? md5( $body ) ),
			'meta'           => array(
				'curated'     => true,
				'source_id'   => $source_id,
				'logical_key' => $logical_key,
				'min_version' => (string) ( $fields['plugin_min_version'] ?? '' ),
				'max_version' => (string) ( $fields['plugin_max_version'] ?? '' ),

				/*
				 * Curated prose is a human's writing. It is not derived from
				 * tokens and must not claim to be — the provenance distinction
				 * is the same one Tier 1 draws, applied to a different author.
				 */
				'provenance'  => array(
					'content' => Provenance::AUTHOR_PROSE,
					'facts'   => Provenance::CURATED,
				),
			),
		);
	}

	/**
	 * The storage key for a curated entry.
	 *
	 * Curated entries are stored beside the extracted ones rather than on top of
	 * them: precedence decides which is served, and the loser is retained so
	 * that switching a curated source off restores the local knowledge instead
	 * of leaving a hole. That requires distinct keys, since `entry_key` is
	 * unique — the logical key from the file is kept in `meta.logical_key`.
	 *
	 * @param string $source_id   Catalog identifier.
	 * @param string $logical_key Key as written in the file.
	 */
	public static function storage_key( string $source_id, string $logical_key ): string {
		return 'curated:' . $source_id . ':' . $logical_key;
	}

	/**
	 * Lift the trailing "## Facts" JSON block, if there is one.
	 *
	 * @param string $body Markdown body.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function extract_facts( string $body ) {
		if ( ! preg_match( '/^##\s+Facts\s*$\R+```json\R(.*?)\R```/ms', $body, $matches ) ) {
			return array();
		}

		$decoded = json_decode( $matches[1], true );

		if ( ! is_array( $decoded ) ) {
			return new \WP_Error(
				'zorem_agent_brain_registry_invalid_facts',
				__( 'The entry\'s Facts block is not valid JSON.', 'zorem-ai-agent-connector' )
			);
		}

		return $decoded;
	}

	/**
	 * The first paragraph of the body, as a fallback summary.
	 *
	 * @param string $body Markdown body.
	 */
	private function first_paragraph( string $body ): string {
		$blocks = preg_split( '/\R{2,}/', trim( $body ) );

		foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
			$block = trim( (string) $block );

			// Skip headings and fences; a summary should read as a sentence.
			if ( '' === $block || 0 === strpos( $block, '#' ) || 0 === strpos( $block, '```' ) ) {
				continue;
			}

			return mb_substr( $block, 0, 300 );
		}

		return '';
	}
}
