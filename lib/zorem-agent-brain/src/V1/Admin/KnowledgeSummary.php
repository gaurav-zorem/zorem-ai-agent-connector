<?php
/**
 * The Website Brain, as a merchant would describe it.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Admin;

use ZoremAgentBrain\V1\Extraction\PluginLocator;
use ZoremAgentBrain\V1\Scope\SectionTiers;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * What the agents can answer from: the store's own text, and each plugin.
 *
 * Ported from AI Agent OS (legacy)'s "What your agents know" screen. A merchant asks
 * *what does my assistant know?*, and "53 entries, roughly 56,800 tokens" is
 * not an answer to it, so this groups the store into what the shop told its
 * customers, what it told WooCommerce, and what was read from each plugin.
 *
 * Counts only what an agent can actually read: a superseded entry and an AI
 * draft waiting for review are read by nobody, so they are counted apart.
 */
final class KnowledgeSummary {

	/**
	 * Most entries read in one go. Above any real install; a partial read is flagged, not hidden.
	 */
	public const LIMIT = 500;

	/**
	 * Knowledge store.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Installed plugins, for their names.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $plugins;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Knowledge store.
	 * @param PluginLocator       $plugins    Installed plugins.
	 */
	public function __construct( KnowledgeRepository $repository, PluginLocator $plugins ) {
		$this->repository = $repository;
		$this->plugins    = $plugins;
	}

	/**
	 * Section names in words, in the order a row is read.
	 *
	 * "abilities" and "shortcodes" are the extractor's vocabulary; a merchant
	 * wants to know what an agent can answer from the row. A section this list
	 * has not caught up with still shows, under its own name.
	 *
	 * @return array<string, string>
	 */
	public static function section_labels(): array {
		return array(
			'overview'   => __( 'what it does', 'zorem-ai-agent-connector' ),
			'abilities'  => __( 'what agents can do with it', 'zorem-ai-agent-connector' ),
			'faq'        => __( 'common questions', 'zorem-ai-agent-connector' ),
			'shortcodes' => __( 'shortcodes', 'zorem-ai-agent-connector' ),
			'manual'     => __( 'its manual', 'zorem-ai-agent-connector' ),
			'document'   => __( 'its documents', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * The summary.
	 *
	 * @return array{readable: int, superseded: int, drafts: int, complete: bool, store: array<int, array<string, string>>, plugins: array<string, array<string, mixed>>, technical: int}
	 */
	public function build(): array {
		$result = $this->repository->query(
			array(
				'per_page'           => self::LIMIT,
				'include_superseded' => true,
			)
		);

		$items  = (array) ( $result['items'] ?? array() );
		$total  = (int) ( $result['total'] ?? count( $items ) );
		$names  = $this->plugins->active_plugins();
		$labels = self::section_labels();

		$summary = array(
			'readable'   => 0,
			'superseded' => 0,
			'drafts'     => 0,
			// Rule 5: a count from a capped read says so.
			'complete'   => $total <= count( $items ),
			'store'      => array(),
			'plugins'    => array(),
			'technical'  => 0,
		);

		$sections = array();

		foreach ( $items as $entry ) {
			if ( ! $entry instanceof KnowledgeEntry ) {
				continue;
			}

			if ( '' !== (string) $entry->meta( 'superseded_by', '' ) ) {
				++$summary['superseded'];
				continue;
			}

			if ( KnowledgeEntry::TRUST_APPROVED !== $entry->get_trust_status() ) {
				++$summary['drafts'];
				continue;
			}

			$source = $entry->get_source_type();

			// The store's own words: its pages, its WooCommerce settings, and what was written here by hand.
			if ( in_array( $source, array( KnowledgeEntry::SOURCE_WP_CONTENT, KnowledgeEntry::SOURCE_STORE_SETTINGS, KnowledgeEntry::SOURCE_MANUAL ), true ) ) {
				++$summary['readable'];
				$summary['store'][] = array(
					'title'   => $entry->get_title(),
					'summary' => $entry->get_summary(),
					'source'  => $source,
				);
				continue;
			}

			$slug = (string) $entry->get_plugin_slug();

			if ( '' === $slug ) {
				continue;
			}

			++$summary['readable'];

			if ( ! isset( $summary['plugins'][ $slug ] ) ) {
				$summary['plugins'][ $slug ] = array(
					// A plugin's name, not its directory; one that is no longer active keeps its slug.
					'name'      => (string) ( $names[ $slug ]['Name'] ?? $slug ),
					'version'   => (string) $entry->get_plugin_version(),
					'knows'     => '',
					'items'     => 0,
					'internal'  => 0,
					'truncated' => false,
					'curated'   => false,
				);
				$sections[ $slug ]           = array();
			}

			++$summary['plugins'][ $slug ]['items'];

			// The version from whichever entry recorded one; not every producer does.
			if ( '' === $summary['plugins'][ $slug ]['version'] && null !== $entry->get_plugin_version() ) {
				$summary['plugins'][ $slug ]['version'] = (string) $entry->get_plugin_version();
			}

			if ( SectionTiers::is_public( $entry->get_section() ) ) {
				$sections[ $slug ][] = $entry->get_section();
			} else {
				++$summary['plugins'][ $slug ]['internal'];
				++$summary['technical'];
			}

			if ( ! empty( $entry->meta( 'truncated', false ) ) ) {
				$summary['plugins'][ $slug ]['truncated'] = true;
			}

			if ( KnowledgeEntry::SOURCE_REMOTE_CURATED === $source ) {
				$summary['plugins'][ $slug ]['curated'] = true;
			}
		}

		foreach ( $summary['plugins'] as $slug => $row ) {
			$have  = array_values( array_unique( $sections[ $slug ] ) );
			$words = array();

			foreach ( $labels as $key => $label ) {
				if ( in_array( $key, $have, true ) ) {
					$words[] = $label;
				}
			}

			foreach ( array_diff( $have, array_keys( $labels ) ) as $extra ) {
				$words[] = $extra;
			}

			// One sentence about the plugin, so only its first letter is raised; multibyte-safe for translations.
			$knows = implode( ', ', $words );

			if ( '' !== $knows ) {
				$knows = mb_strtoupper( mb_substr( $knows, 0, 1 ) ) . mb_substr( $knows, 1 );
			}

			$summary['plugins'][ $slug ]['knows'] = $knows;
		}

		uasort(
			$summary['plugins'],
			static fn ( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] )
		);

		return $summary;
	}
}
