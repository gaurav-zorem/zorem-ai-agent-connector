<?php
/**
 * Read everything again.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Curated\CuratedSync;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Sources\WpContentSource;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;
use ZoremAgentBrain\V1\Store\Precedence;

defined( 'ABSPATH' ) || exit;

/**
 * The one button somebody presses when the Brain looks wrong.
 *
 * Ported from AI Agent OS (legacy)'s rebuild. Every source this site reads by itself
 * is read again: plugins are queued afresh (the background ticks do the
 * work), the WooCommerce settings and the bundled documentation are re-read
 * now, and so are the pages the merchant chose. Rebuilding everything except
 * the store's own settings would leave exactly the shipping and checkout
 * answers they were probably checking.
 *
 * Precedence is then recomputed for every plugin with entries. A superseded
 * entry is invisible to every agent, and nothing else clears a stale flag:
 * precedence is otherwise resolved only when a better entry lands for that
 * plugin. `resolve()` restores as readily as it supersedes, so this is a
 * recomputation, not a reset.
 */
final class FullRebuild {

	/**
	 * Plugin reading.
	 *
	 * @var IngestionOrchestrator
	 */
	private IngestionOrchestrator $orchestrator;

	/**
	 * Store settings.
	 *
	 * @var WooCommerceSource
	 */
	private WooCommerceSource $store;

	/**
	 * Bundled documentation.
	 *
	 * @var CuratedSync
	 */
	private CuratedSync $curated;

	/**
	 * Chosen pages.
	 *
	 * @var WpContentSource
	 */
	private WpContentSource $pages;

	/**
	 * Knowledge store.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Which entry wins.
	 *
	 * @var Precedence
	 */
	private Precedence $precedence;

	/**
	 * Constructor.
	 *
	 * @param IngestionOrchestrator $orchestrator Plugin reading.
	 * @param WooCommerceSource     $store        Store settings.
	 * @param CuratedSync           $curated      Bundled documentation.
	 * @param WpContentSource       $pages        Chosen pages.
	 * @param KnowledgeRepository   $repository   Knowledge store.
	 * @param Precedence            $precedence   Which entry wins.
	 */
	public function __construct( IngestionOrchestrator $orchestrator, WooCommerceSource $store, CuratedSync $curated, WpContentSource $pages, KnowledgeRepository $repository, Precedence $precedence ) {
		$this->orchestrator = $orchestrator;
		$this->store        = $store;
		$this->curated      = $curated;
		$this->pages        = $pages;
		$this->repository   = $repository;
		$this->precedence   = $precedence;
	}

	/**
	 * Read everything again, and say what happened.
	 *
	 * `pages` is how many chosen pages were read again, `pages_changed` how
	 * many of them had changed and were written.
	 *
	 * @return array{plugins: array<string, mixed>, store: array<string, mixed>, curated: array<string, mixed>, pages: int, pages_changed: int, precedence_restored: int}
	 */
	public function run(): array {
		$report = array(
			'plugins'             => $this->orchestrator->rebuild(),
			'store'               => $this->store->sync(),
			'curated'             => $this->curated->sync(),
			'pages'               => 0,
			'pages_changed'       => 0,
			'precedence_restored' => 0,
		);

		foreach ( $this->pages->selected() as $post_id ) {
			++$report['pages'];

			if ( $this->pages->ingest( (int) $post_id ) ) {
				++$report['pages_changed'];
			}
		}

		$slugs = array();

		foreach ( $this->repository->topics(
			array(
				'per_page'           => 500,
				'include_stale'      => true,
				'include_superseded' => true,
			)
		) as $topic ) {
			$slug = (string) ( $topic['plugin_slug'] ?? '' );

			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}

		foreach ( array_keys( $slugs ) as $slug ) {
			$report['precedence_restored'] += (int) ( $this->precedence->resolve( (string) $slug )['restored'] ?? 0 );
		}

		return $report;
	}
}
