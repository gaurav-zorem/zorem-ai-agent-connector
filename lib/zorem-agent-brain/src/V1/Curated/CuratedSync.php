<?php
/**
 * Keep the curated catalog's knowledge in step with the site, unattended.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

use ZoremAgentBrain\V1\Settings;
use ZoremAgentBrain\V1\Extraction\PluginLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Ingest curated knowledge for every plugin the catalog covers.
 *
 * ## Why this exists
 *
 * The catalog shipped with a screen and no schedule. `CuratedIngestor::ingest()`
 * was reachable from exactly one place — a per-plugin override button on the
 * Curated catalog panel — so curated knowledge only arrived if a merchant found
 * that panel and pressed the button on the right row. Nothing on a fresh
 * install ever called it, which made the catalog a feature that existed and did
 * not happen.
 *
 * Hand-written documentation for a plugin as large as WooCommerce is better
 * than anything static analysis can produce from its source, and it is bundled
 * with the plugin. Waiting to be asked for it was the wrong default.
 *
 * ## Alongside extraction, not instead of it
 *
 * `CuratedCoverage` was designed to remove covered plugins from the extraction
 * queue entirely. That is the more efficient arrangement and the more fragile
 * one: a plugin claimed by the catalog and then failed by it ends up with no
 * knowledge at all. This runs the two in parallel instead and lets precedence
 * settle it — curated entries outrank extracted ones for the same section, so
 * the better text wins where it exists and local extraction still covers
 * everything it does not.
 */
final class CuratedSync {

	/**
	 * The catalog.
	 *
	 * @var CuratedKbAdapterInterface
	 */
	private CuratedKbAdapterInterface $adapter;

	/**
	 * Ingestion.
	 *
	 * @var CuratedIngestor
	 */
	private CuratedIngestor $ingestor;

	/**
	 * Installed plugins.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param CuratedKbAdapterInterface $adapter  The catalog.
	 * @param CuratedIngestor           $ingestor Ingestion.
	 * @param PluginLocator             $locator  Installed plugins.
	 * @param Settings                  $settings Settings.
	 */
	public function __construct(
		CuratedKbAdapterInterface $adapter,
		CuratedIngestor $ingestor,
		PluginLocator $locator,
		Settings $settings
	) {
		$this->adapter  = $adapter;
		$this->ingestor = $ingestor;
		$this->locator  = $locator;
		$this->settings = $settings;
	}

	/**
	 * Run on the extraction tick, which is also what a rebuild drives.
	 */
	public function register(): void {
		add_action( \ZoremAgentBrain\V1\Ingestion\IngestionScheduler::EVENT, array( $this, 'sync' ) );
	}

	/**
	 * Active plugins the catalogue covers, with whether each is kept on local knowledge.
	 *
	 * @return array<int, array{slug: string, name: string, local: bool}>
	 */
	public function covered_plugins(): array {
		$active = $this->locator->active_plugins();
		$list   = array();

		foreach ( $this->adapter->covered_slugs( array_map( 'strval', array_keys( $active ) ) ) as $slug ) {
			$list[] = array(
				'slug'  => (string) $slug,
				'name'  => (string) ( $active[ $slug ]['Name'] ?? $slug ),
				'local' => CuratedOverrides::is_local( (string) $slug ),
			);
		}

		return $list;
	}

	/**
	 * Keep a plugin on this site's own knowledge, or give it back to the catalogue.
	 *
	 * Switching to local removes the plugin's curated entries (precedence then
	 * shows the locally extracted ones again) and queues it for extraction, which
	 * coverage had been skipping. Switching back loads the catalogue for it.
	 *
	 * @param string $slug      Plugin slug.
	 * @param bool   $use_local Whether to use this site's own knowledge.
	 * @return array<string, mixed>
	 */
	public function set_local( string $slug, bool $use_local ): array {
		CuratedOverrides::set( $slug, $use_local );

		if ( $use_local ) {
			$removed = $this->ingestor->remove_source( $this->adapter->get_source_id(), $slug );
			\ZoremAgentBrain\V1\Brain::get( \ZoremAgentBrain\V1\Ingestion\IngestionOrchestrator::class )->activated( $slug );

			return array(
				'local'   => true,
				'removed' => $removed,
			);
		}

		return array(
			'local' => false,
			'sync'  => $this->sync(),
		);
	}

	/**
	 * Ingest what the catalog covers.
	 *
	 * @return array<string, mixed>
	 */
	public function sync(): array {
		$report = array(
			'enabled'  => $this->settings->bool( 'knowledge_curated_kb' ),
			'plugins'  => array(),
			'ingested' => 0,
		);

		if ( ! $report['enabled'] ) {
			return $report;
		}

		$active = $this->locator->active_plugins();

		/*
		 * The adapter decides coverage, including the version range. A shop on
		 * a WooCommerce release the catalog was not written for is not covered,
		 * and gets local extraction — documentation for the wrong version is
		 * worse than none, because it reads exactly as confidently.
		 */
		$covered = $this->adapter->covered_slugs( array_map( 'strval', array_keys( $active ) ) );

		foreach ( $covered as $slug ) {
			// Kept on this site's own knowledge by the administrator: not loaded from the catalogue.
			if ( CuratedOverrides::is_local( (string) $slug ) ) {
				$report['plugins'][ (string) $slug ] = array( 'local' => true );
				continue;
			}

			$version = (string) ( $active[ $slug ]['Version'] ?? '' );
			$result  = $this->ingestor->ingest( $this->adapter, (string) $slug, $version );

			$report['plugins'][ (string) $slug ] = $result;
			$report['ingested']                 += (int) ( $result['ingested'] ?? 0 );
		}

		return $report;
	}
}
