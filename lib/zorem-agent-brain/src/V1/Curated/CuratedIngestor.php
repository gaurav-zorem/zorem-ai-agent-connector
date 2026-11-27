<?php
/**
 * Brings curated entries into the knowledge base.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\Precedence;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores what a curated source provides, then re-resolves precedence.
 *
 * Ingestion is idempotent and reversible. Entries are written under
 * source-qualified keys, so they sit beside the extracted ones rather than on
 * top of them; precedence then decides which is served. Removing a source
 * deletes only its own entries and re-resolves, which restores whatever it had
 * superseded — the property that makes turning the catalog off safe.
 */
final class CuratedIngestor {

	/**
	 * Knowledge storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Precedence resolution.
	 *
	 * @var Precedence
	 */
	private Precedence $precedence;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Knowledge storage.
	 * @param Precedence          $precedence Precedence resolution.
	 */
	public function __construct( KnowledgeRepository $repository, Precedence $precedence ) {
		$this->repository = $repository;
		$this->precedence = $precedence;
	}

	/**
	 * Ingest one plugin's curated entries.
	 *
	 * @param CuratedKbAdapterInterface $adapter        The source.
	 * @param string                    $plugin_slug    Plugin slug.
	 * @param string                    $plugin_version Installed version.
	 * @return array<string, mixed> A report.
	 */
	public function ingest( CuratedKbAdapterInterface $adapter, string $plugin_slug, string $plugin_version ): array {
		$report = array(
			'plugin'     => $plugin_slug,
			'source'     => $adapter->get_source_id(),
			'ingested'   => 0,
			'removed'    => 0,
			'errors'     => array(),
			'precedence' => array(),
		);

		$entries = $adapter->fetch( $plugin_slug, $plugin_version );

		if ( is_wp_error( $entries ) ) {
			/*
			 * The interface forbids this, but an implementation can always be
			 * wrong. Treating it as no coverage keeps the contract's promise
			 * whatever the adapter does.
			 */
			$report['errors'][] = $entries->get_error_message();
			$entries            = array();
		}

		$seen = array();

		foreach ( (array) $entries as $entry ) {
			$id = $this->repository->upsert( $entry );

			if ( is_wp_error( $id ) ) {
				$report['errors'][] = $id->get_error_message();
				continue;
			}

			$seen[] = (string) $entry['entry_key'];
			++$report['ingested'];
		}

		if ( method_exists( $adapter, 'rejections' ) ) {
			foreach ( (array) $adapter->rejections() as $rejection ) {
				$report['errors'][] = (string) $rejection;
			}
		}

		$report['removed']    = $this->remove_stale_curated( $adapter->get_source_id(), $plugin_slug, $seen );
		$report['precedence'] = $this->precedence->resolve( $plugin_slug );

		return $report;
	}

	/**
	 * Remove this source's entries for a plugin, and restore what they hid.
	 *
	 * @param string $source_id   Source identifier.
	 * @param string $plugin_slug Plugin slug.
	 * @return array<string, mixed>
	 */
	public function remove_source( string $source_id, string $plugin_slug ): array {
		$removed = $this->remove_stale_curated( $source_id, $plugin_slug, array() );

		return array(
			'removed'    => $removed,
			'precedence' => $this->precedence->resolve( $plugin_slug ),
		);
	}

	/**
	 * Delete curated entries this source no longer provides.
	 *
	 * @param string             $source_id Source identifier.
	 * @param string             $plugin_slug Plugin slug.
	 * @param array<int, string> $keep      Keys still provided.
	 */
	private function remove_stale_curated( string $source_id, string $plugin_slug, array $keep ): int {
		$existing = $this->repository->query(
			array(
				'plugin_slug'        => $plugin_slug,
				'source_type'        => KnowledgeEntry::SOURCE_REMOTE_CURATED,
				'per_page'           => 500,
				'include_stale'      => true,
				'include_superseded' => true,
			)
		);

		$removed = 0;

		foreach ( $existing['items'] as $entry ) {
			if ( $source_id !== (string) $entry->get_source_ref() ) {
				continue;
			}

			if ( in_array( $entry->get_entry_key(), $keep, true ) ) {
				continue;
			}

			$this->repository->delete( $entry->get_id() );
			++$removed;
		}

		return $removed;
	}
}
