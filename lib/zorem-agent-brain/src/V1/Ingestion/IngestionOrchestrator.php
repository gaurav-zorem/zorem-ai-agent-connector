<?php
/**
 * Runs plugin extraction, one bounded tick at a time.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Settings;
use ZoremAgentBrain\V1\Extraction\AbilityOriginRecorder;
use ZoremAgentBrain\V1\Extraction\ExtractionContext;
use ZoremAgentBrain\V1\Extraction\ExtractorInterface;
use ZoremAgentBrain\V1\Extraction\PluginAttributionResolver;
use ZoremAgentBrain\V1\Extraction\PluginLocator;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The Brain's ingestion loop.
 *
 * One plugin per tick, and often only part of one plugin: WooCommerce is 24.9
 * MB of PHP and a token array runs 45-57 times its source, so "read everything
 * and write the results" is not a thing this can do inside one request on a
 * normal host. Every design choice below follows from that.
 *
 * The loop is deliberately boring and interruptible. A tick claims the lock,
 * does a bounded amount of work, persists exactly where it got to, and leaves.
 * If it is killed halfway — by memory, by a timeout, by a deploy — the next
 * tick resumes from the cursor rather than starting over. Starting over is not
 * a slow success; against a wall the process cannot clear, it is an infinite
 * loop that reports progress forever and produces nothing.
 */
final class IngestionOrchestrator {

	/**
	 * Corpus ceiling per plugin, across all ticks.
	 */
	public const MAX_FILES = 1500;
	public const MAX_BYTES = 12582912;

	/**
	 * Entries one plugin may produce.
	 */
	public const MAX_ENTRIES = 200;

	/**
	 * Plugin locator.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Attribution resolver, which also owns the token scan.
	 *
	 * @var PluginAttributionResolver
	 */
	private PluginAttributionResolver $resolver;

	/**
	 * Knowledge storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Work queue.
	 *
	 * @var ExtractionQueue
	 */
	private ExtractionQueue $queue;

	/**
	 * Pass lock.
	 *
	 * @var ExtractionLock
	 */
	private ExtractionLock $lock;

	/**
	 * Curated coverage check.
	 *
	 * @var CuratedCoverage
	 */
	private CuratedCoverage $curated;

	/**
	 * Plugin fingerprinting.
	 *
	 * @var PluginChecksum
	 */
	private PluginChecksum $checksum;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Ability registration origins.
	 *
	 * @var AbilityOriginRecorder
	 */
	private AbilityOriginRecorder $origins;

	/**
	 * Extractors, in run order.
	 *
	 * @var array<int, ExtractorInterface>
	 */
	private array $extractors;

	/**
	 * Constructor.
	 *
	 * @param PluginLocator                  $locator    Plugin locator.
	 * @param PluginAttributionResolver      $resolver   Attribution resolver.
	 * @param KnowledgeRepository            $repository Knowledge storage.
	 * @param ExtractionQueue                $queue      Work queue.
	 * @param ExtractionLock                 $lock       Pass lock.
	 * @param CuratedCoverage                $curated    Curated coverage check.
	 * @param PluginChecksum                 $checksum   Fingerprinting.
	 * @param Settings                       $settings   Plugin settings.
	 * @param AbilityOriginRecorder          $origins    Ability origins.
	 * @param array<int, ExtractorInterface> $extractors Extractors, in run order.
	 */
	public function __construct(
		PluginLocator $locator,
		PluginAttributionResolver $resolver,
		KnowledgeRepository $repository,
		ExtractionQueue $queue,
		ExtractionLock $lock,
		CuratedCoverage $curated,
		PluginChecksum $checksum,
		Settings $settings,
		AbilityOriginRecorder $origins,
		array $extractors = array()
	) {
		$this->locator    = $locator;
		$this->resolver   = $resolver;
		$this->repository = $repository;
		$this->queue      = $queue;
		$this->lock       = $lock;
		$this->curated    = $curated;
		$this->checksum   = $checksum;
		$this->settings   = $settings;
		$this->origins    = $origins;
		$this->extractors = array_values( $extractors );
	}

	/**
	 * Do one tick's worth of work.
	 *
	 * @param string $trigger What asked for this tick.
	 * @return array<string, mixed> A report, for the log and the admin screen.
	 */
	public function run_tick( string $trigger = 'cron' ): array {
		$report = array(
			'trigger' => $trigger,
			'status'  => 'idle',
			'slug'    => '',
		);

		if ( ! $this->settings->bool( 'knowledge_enabled' ) ) {
			$report['status'] = 'disabled';

			return $report;
		}

		/*
		 * A tick that finds the lock held exits silently. It does not wait, and
		 * it does not do "just the safe part" — two passes interleaving on one
		 * cursor is how a resume point ends up pointing at a file neither of
		 * them read.
		 */
		if ( ! $this->lock->acquire() ) {
			$report['status'] = 'locked';

			return $report;
		}

		try {
			$report['queue'] = $this->refresh_queue();

			$job = $this->queue->next();

			if ( null === $job ) {
				$report['status'] = 'idle';

				return $report;
			}

			$slug           = (string) $job['slug'];
			$report['slug'] = $slug;

			$active = $this->locator->active_plugins();

			if ( ! isset( $active[ $slug ] ) ) {
				$this->queue->remove( $slug );
				$report['status'] = 'gone';

				return $report;
			}

			$data     = $active[ $slug ];
			$checksum = $this->checksum->for_plugin( $slug, $data );

			/*
			 * The checksum is evaluated here, at dequeue, not at enqueue. A
			 * plugin updated twice while it sat in the queue is extracted once,
			 * at the version it actually has now — checking on the way in would
			 * have compared against a version that no longer exists on disk.
			 */
			if ( $this->is_already_current( $slug, $checksum ) ) {
				$this->queue->complete( $slug, array( 'skipped' => 'unchanged' ) );
				$report['status'] = 'skipped_unchanged';

				return $report;
			}

			/*
			 * The plugin changed under a pass already in progress. The cursor
			 * indexes a file list that no longer describes this plugin, so it is
			 * discarded and the pass restarts — resuming into the new version
			 * would mix facts from two different releases into one entry.
			 */
			if ( '' !== (string) ( $job['checksum'] ?? '' ) && (string) $job['checksum'] !== $checksum ) {
				$this->queue->enqueue( $slug, 'changed', true, array( 'has_abilities' => ! empty( $job['has_abilities'] ) ) );
				$job             = (array) $this->queue->get( $slug );
				$job['slug']     = $slug;
				$report['reset'] = true;
			}

			/*
			 * Exhaustion is checked before the claim, since claiming counts a
			 * tick. A plugin that cannot finish inside this host's budget stops
			 * here — permanently, with what it did produce kept and the reason
			 * recorded — rather than holding the head of the queue and starving
			 * everything behind it.
			 */
			if ( $this->queue->is_exhausted( $job ) ) {
				$this->finalise_partial( $slug, $job );
				$report['status'] = 'partial';

				return $report;
			}

			$claimed = $this->queue->claim( $slug, $checksum );

			if ( null === $claimed ) {
				$report['status'] = 'gone';

				return $report;
			}

			$claimed['slug'] = $slug;

			return array_merge( $report, $this->run_job( $claimed, $data ) );
		} finally {
			$this->lock->release();
		}
	}

	/**
	 * Work one claimed job.
	 *
	 * @param array<string, mixed> $job  Claimed job.
	 * @param array<string, mixed> $data Plugin header data.
	 * @return array<string, mixed>
	 */
	private function run_job( array $job, array $data ): array {
		$slug        = (string) $job['slug'];
		$accumulated = is_array( $job['scan'] ?? null ) ? $job['scan'] : array();
		$offset      = (int) ( $job['file_offset'] ?? 0 );

		$scan = $this->resolver->scan_plugin(
			$slug,
			self::MAX_FILES,
			self::MAX_BYTES,
			$offset,
			$this->tick_files()
		);

		$accumulated = $this->merge_scan( $accumulated, $scan );

		if ( empty( $scan['complete'] ) ) {
			/*
			 * Still reading. The cursor is written before this tick ends, so
			 * whatever happens to the process next, the following tick starts at
			 * the file after the last one attempted.
			 */
			$this->queue->save_cursor(
				$slug,
				(int) $scan['next_offset'],
				(int) ( $job['extractor_index'] ?? 0 ),
				$accumulated
			);

			return array(
				'status'          => 'scanning',
				'offset'          => (int) $scan['next_offset'],
				'files_remaining' => (int) $scan['files_remaining'],
				'stop_reason'     => $scan['stop_reason'],
			);
		}

		return $this->run_extractors( $slug, $data, $job, $accumulated );
	}

	/**
	 * How many files one tick may read.
	 *
	 * Filterable because hosts differ by more than an order of magnitude in what
	 * they will allow a cron request to allocate, and because the resume tests
	 * need a tick small enough to force several of them.
	 */
	private function tick_files(): int {
		/**
		 * Filters the per-tick file budget.
		 *
		 * @param int $files Files one extraction tick may read.
		 */
		$files = (int) apply_filters( 'zorem_agent_brain_extraction_tick_files', ExtractionQueue::TICK_FILES );

		return max( 1, $files );
	}

	/**
	 * Run the extractors for a plugin whose scan is finished.
	 *
	 * Extractors are indexed and the index is part of the cursor. A tick that
	 * dies inside extractor five resumes at five: rerunning the first four would
	 * be wasted work, and skipping to six would lose a section with nothing to
	 * show that it was ever missing.
	 *
	 * @param string               $slug        Plugin slug.
	 * @param array<string, mixed> $data        Plugin header data.
	 * @param array<string, mixed> $job         Claimed job.
	 * @param array<string, mixed> $accumulated Accumulated scan.
	 * @return array<string, mixed>
	 */
	private function run_extractors( string $slug, array $data, array $job, array $accumulated ): array {
		$reconciled = $this->resolver->reconcile( $slug, $accumulated['calls'] ?? array() );
		$runtime    = $this->resolver->runtime_artefacts();

		$context = new ExtractionContext(
			$slug,
			$data,
			$accumulated,
			$runtime[ $slug ] ?? array(),
			$reconciled
		);

		$start   = (int) ( $job['extractor_index'] ?? 0 );
		$written = (int) ( $accumulated['entries_written'] ?? 0 );
		$keys    = array();
		$failed  = array();

		foreach ( $this->extractors as $index => $extractor ) {
			if ( $index < $start ) {
				continue;
			}

			if ( ! $extractor->supports( $context ) ) {
				continue;
			}

			foreach ( $extractor->extract( $context ) as $entry ) {
				if ( $written >= self::MAX_ENTRIES ) {
					$failed[] = 'entry_cap';
					break 2;
				}

				$entry['meta'] = array_merge(
					is_array( $entry['meta'] ?? null ) ? $entry['meta'] : array(),
					array(
						'extracted_at' => time(),
						'extractor'    => $extractor->get_id(),
					)
				);

				$id = $this->repository->upsert( $entry );

				if ( is_wp_error( $id ) ) {
					$failed[] = $extractor->get_id() . ':' . $id->get_error_code();
					continue;
				}

				$keys[] = (string) $entry['entry_key'];
				++$written;
			}

			/*
			 * The cursor moves past each extractor as it finishes, carrying the
			 * running entry count so a resumed pass cannot exceed the cap by
			 * forgetting what it already wrote.
			 */
			$accumulated['entries_written'] = $written;
			$this->queue->save_cursor( $slug, (int) ( $job['file_offset'] ?? 0 ), $index + 1, $accumulated );
		}

		$this->prune_removed_entries( $slug, $keys );

		$stats = array(
			'entries'        => $written,
			'files'          => (int) ( $accumulated['files'] ?? 0 ),
			'ticks'          => (int) ( $job['ticks'] ?? 0 ),
			'truncated'      => ! empty( $accumulated['truncated'] ),
			'skipped_memory' => (int) ( $accumulated['skipped_memory'] ?? 0 ),
			'skipped_large'  => (int) ( $accumulated['skipped_large'] ?? 0 ),
			'unparseable'    => (int) ( $accumulated['unparseable'] ?? 0 ),
			'errors'         => $failed,

			/*
			 * Recorded at the moment both passes exist. Reconciliation needs the
			 * static scan and the live registry together, and the scan is dropped
			 * once the pass completes — asking for these numbers afterwards would
			 * silently answer from the runtime pass alone and report everything as
			 * runtime_only.
			 */
			'attribution'    => PluginAttributionResolver::summarise( $reconciled ),
			'evidence'       => PluginAttributionResolver::summarise_evidence( $reconciled ),
		);

		$this->queue->complete( $slug, $stats );

		/**
		 * Fires when a plugin's extraction pass completes and its entries are stored.
		 *
		 * A notification, never an authorisation input: a host reacts to it (Agent
		 * OS Core drafts a skill from the new facts) and reads the facts through
		 * the store, not from this payload.
		 *
		 * @param string               $slug  Plugin slug.
		 * @param array<string, mixed> $stats What the pass did.
		 */
		do_action( 'zorem_agent_brain_plugin_extracted', $slug, $stats );

		return array_merge( array( 'status' => 'complete' ), $stats );
	}

	/**
	 * Bring the queue in line with the site, applying the ordering rules.
	 *
	 * Curated coverage is consulted here, before anything is enqueued. A plugin
	 * the catalog already describes never enters the local queue at all — with
	 * the catalog setting off nothing is fetched and it queues normally, which
	 * is the whole of the opt-in promise.
	 *
	 * @return array<string, mixed>
	 */
	public function refresh_queue(): array {
		$active = $this->locator->active_plugins();

		// Before duplicates and curated coverage are taken out: both are still active plugins.
		$stale = $this->reconcile_stale( array_map( 'strval', array_keys( $active ) ) );

		$duplicates = $this->resolve_duplicates( $active );

		foreach ( $duplicates as $slug ) {
			unset( $active[ $slug ] );
			$this->queue->remove( $slug );
		}

		$covered = $this->curated->covered( array_map( 'strval', array_keys( $active ) ) );

		foreach ( $covered as $slug ) {
			unset( $active[ $slug ] );
			$this->queue->remove( $slug );
		}

		$reconciled = $this->queue->reconcile(
			$active,
			$this->ability_bearing_slugs(),
			$this->checksum->for_plugins( $active )
		);

		return array_merge(
			$reconciled,
			array(
				'curated_skipped'   => $covered,
				'curated_error'     => $this->curated->last_error(),
				'duplicate_skipped' => array_values( $duplicates ),
				'stale'             => $stale,
				'summary'           => $this->queue->summary(),
			)
		);
	}

	/**
	 * Bring the stale marks in line with which plugins are active.
	 *
	 * The marks are normally set and cleared by the activation hooks, but those
	 * only fire while a copy of the kit is running, and several real cases miss
	 * them:
	 *
	 * - The host itself. Deactivating it marks its own entries stale while the
	 *   kit is running, but on reactivation `activated_plugin` fires before the
	 *   kit boots, so the mark stayed for good: the screen said "Plugin
	 *   inactive" for an active plugin and agents could not read its knowledge.
	 * - A plugin switched off, or deleted, while no host was active. Its entries
	 *   kept saying "In use" and stayed readable by agents.
	 * - A plugin folder renamed: the old slug's entries were never marked.
	 *
	 * So every refresh checks the store against the site. An active plugin has
	 * no stale entries. An installed plugin that is switched off has only stale
	 * ones, kept exactly as on deactivation so switching it back on is cheap.
	 * A plugin that is no longer installed has none: its entries are deleted,
	 * as on `deleted_plugin`, which also misses deletions made while no host
	 * was running. A merchant who deleted a plugin should not find its name in
	 * the Brain. Deletion waits while WordPress is updating anything, because
	 * an update removes a plugin's folder for a moment before writing the new
	 * one.
	 *
	 * @param array<int, string> $active_slugs Slugs of the active plugins.
	 * @return array{cleared: array<int, string>, marked: array<int, string>, removed: array<int, string>} Slugs changed.
	 */
	private function reconcile_stale( array $active_slugs ): array {
		$changed = array(
			'cleared' => array(),
			'marked'  => array(),
			'removed' => array(),
		);

		$installed = array_map( 'strval', array_keys( $this->locator->all_plugins() ) );
		$updating  = self::updates_running();

		foreach ( $this->repository->plugin_stale_summary() as $slug => $counts ) {
			$slug   = (string) $slug;
			$active = in_array( $slug, $active_slugs, true );

			if ( ! $active && ! $updating && ! in_array( $slug, $installed, true ) ) {
				$this->deleted( $slug );
				$changed['removed'][] = $slug;
			} elseif ( $active && $counts['stale'] > 0 ) {
				$this->repository->set_plugin_stale( $slug, false );
				$changed['cleared'][] = $slug;
			} elseif ( ! $active && $counts['stale'] < $counts['entries'] ) {
				$this->repository->set_plugin_stale( $slug, true );
				$changed['marked'][] = $slug;
			}
		}

		return $changed;
	}

	/**
	 * Whether WordPress is in the middle of an update or install.
	 *
	 * Maintenance mode covers bulk and core updates; the upgrader's locks cover
	 * automatic updates. A plugin being updated has no folder for a moment, and
	 * deleting its knowledge then would throw away a full extraction for nothing.
	 */
	private static function updates_running(): bool {
		if ( wp_installing() ) {
			return true;
		}

		if ( function_exists( 'wp_is_maintenance_mode' ) && wp_is_maintenance_mode() ) {
			return true;
		}

		return false !== get_option( 'auto_updater.lock', false ) || false !== get_option( 'core_updater.lock', false );
	}

	/**
	 * Add a plugin to the queue.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $reason Why.
	 * @param bool   $reset  Discard any cursor.
	 */
	public function enqueue( string $slug, string $reason = 'manual', bool $reset = false ): void {
		if ( ! $this->settings->bool( 'knowledge_enabled' ) ) {
			return;
		}

		if ( array() !== $this->curated->covered( array( $slug ) ) ) {
			return;
		}

		$this->queue->enqueue(
			$slug,
			$reason,
			$reset,
			array( 'has_abilities' => in_array( $slug, $this->ability_bearing_slugs(), true ) )
		);
	}

	/**
	 * A plugin was deactivated.
	 *
	 * Its queued job goes away — extracting software that is not running wastes
	 * the tick — and its entries are marked stale rather than deleted.
	 * Reactivation is common and rebuilding is expensive.
	 *
	 * @param string $slug Plugin slug.
	 */
	public function deactivated( string $slug ): void {
		$this->queue->remove( $slug );
		$this->repository->set_plugin_stale( $slug, true );
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $slug Plugin slug.
	 */
	public function activated( string $slug ): void {
		$this->repository->set_plugin_stale( $slug, false );
		$this->enqueue( $slug, 'activated' );
	}

	/**
	 * A plugin was deleted.
	 *
	 * Unlike deactivation, its entries go: the plugin is no longer on the site,
	 * and a merchant who deleted it should not find its name in the Brain.
	 * Installing it again extracts it afresh.
	 *
	 * @param string $slug Plugin slug.
	 */
	public function deleted( string $slug ): void {
		if ( '' === $slug ) {
			return;
		}

		$this->queue->remove( $slug );
		$this->repository->delete_for_plugin( $slug );
	}

	/**
	 * Re-extract everything from scratch.
	 *
	 * @return array<string, mixed>
	 */
	public function rebuild(): array {
		ExtractionLock::force_release();
		$this->queue->clear();

		return $this->refresh_queue();
	}

	/**
	 * Whether stored knowledge already describes this exact plugin state.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $checksum Current fingerprint.
	 */
	private function is_already_current( string $slug, string $checksum ): bool {
		$job = $this->queue->get( $slug );

		if ( null === $job ) {
			return false;
		}

		if ( ExtractionQueue::STATUS_DONE !== ( $job['status'] ?? '' ) ) {
			return false;
		}

		if ( (string) ( $job['checksum'] ?? '' ) !== $checksum ) {
			return false;
		}

		/*
		 * A matching fingerprint is not enough on its own. If the entries were
		 * deleted — by a rebuild, by hand, by a half-finished uninstall — the
		 * plugin must be read again, or the Brain would consider a plugin it
		 * knows nothing about permanently up to date.
		 */
		$existing = $this->repository->query(
			array(
				'plugin_slug'   => $slug,
				'per_page'      => 1,
				'include_stale' => true,
			)
		);

		return $existing['total'] > 0;
	}

	/**
	 * Give up on a plugin that has run out of ticks.
	 *
	 * What was extracted stays. The entries are marked so that anything reading
	 * them knows they describe part of a plugin rather than all of it — "no
	 * shortcodes found" and "we stopped looking" are different claims, and only
	 * one of them is safe to act on.
	 *
	 * @param string               $slug Plugin slug.
	 * @param array<string, mixed> $job  Job record.
	 */
	private function finalise_partial( string $slug, array $job ): void {
		/*
		 * Why the ticks ran out matters more than the fact that they did. On a
		 * memory-constrained host every tick stops on the guard, so the plugin
		 * exhausts its allowance without ever hitting a file budget — and
		 * telling that merchant their plugin is too large would send them to
		 * fix the wrong thing. The distinction is taken from the accumulated
		 * scan before `terminate()` clears it.
		 */
		$scan        = is_array( $job['scan'] ?? null ) ? $job['scan'] : array();
		$stop_reason = (string) ( $scan['stop_reason'] ?? '' );

		if ( (int) ( $job['attempts'] ?? 0 ) > ExtractionQueue::MAX_ATTEMPTS ) {
			$reason = 'retry_cap';
		} elseif ( 'memory' === $stop_reason || (int) ( $scan['skipped_memory'] ?? 0 ) > 0 ) {
			$reason = 'memory';
		} else {
			$reason = 'tick_cap';
		}

		$this->queue->terminate( $slug, ExtractionQueue::STATUS_PARTIAL, $reason );

		$entries = $this->repository->query(
			array(
				'plugin_slug'   => $slug,
				'per_page'      => self::MAX_ENTRIES,
				'include_stale' => true,
			)
		);

		foreach ( $entries['items'] as $entry ) {
			// Merge, not replace: these entries carry provenance that marking
			// them incomplete has no business discarding.
			$this->repository->merge_meta(
				$entry->get_id(),
				array(
					'truncated'     => true,
					'truncated_why' => $reason,
				)
			);
		}
	}

	/**
	 * Delete entries a re-extraction no longer produces.
	 *
	 * A plugin that dropped a shortcode in its latest release should stop being
	 * described as having one. Only entries this extractor set owns are removed,
	 * and only when the pass that replaced them actually produced something —
	 * otherwise a pass that failed early would look like a plugin that lost all
	 * its features.
	 *
	 * **Pruning is confined to sections this pass actually covered.** Found in
	 * a degraded run of the legacy plugin: a pass under WP-CLI has no `rest_api_init` and a
	 * thinner ability registry, so it produces no `rest` entry and fewer
	 * `abilities` entries — and the old rule read that as "the plugin no longer
	 * has REST routes" and deleted knowledge a web-request pass had gathered.
	 * Exporting the registry with extraction from the CLI silently made the Brain worse.
	 *
	 * "This pass produced nothing for that section" and "that section is empty
	 * now" are different claims, and only one of them is a reason to delete. So
	 * a section the pass could not see is left alone entirely.
	 *
	 * @param string             $slug Plugin slug.
	 * @param array<int, string> $keys Entry keys just written.
	 */
	private function prune_removed_entries( string $slug, array $keys ): void {
		if ( array() === $keys ) {
			return;
		}

		$existing = $this->repository->query(
			array(
				'plugin_slug'   => $slug,
				'source_type'   => KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED,
				'per_page'      => self::MAX_ENTRIES,
				'include_stale' => true,
			)
		);

		$covered = array();

		foreach ( $existing['items'] as $entry ) {
			if ( in_array( $entry->get_entry_key(), $keys, true ) ) {
				$covered[ $entry->get_section() ] = true;
			}
		}

		foreach ( $existing['items'] as $entry ) {
			if ( in_array( $entry->get_entry_key(), $keys, true ) ) {
				continue;
			}

			if ( ! isset( $covered[ $entry->get_section() ] ) ) {
				continue;
			}

			$this->repository->delete( $entry->get_id() );
		}
	}

	/**
	 * Merge one tick's scan into the accumulated result.
	 *
	 * Names are deduplicated; counters add up; truncation is sticky, because a
	 * plugin that was truncated on tick three is truncated no matter how cleanly
	 * tick four finishes.
	 *
	 * Comment prose is deliberately not carried between ticks. Nothing consumes
	 * it today — the extractors that mention prose read the readme and the
	 * header, not the token scan — and accumulating every comment in WooCommerce
	 * into an option would cost megabytes per tick for no reader. Anything that
	 * later wants prose must either persist it here or run inside one tick.
	 *
	 * @param array<string, mixed> $accumulated Accumulated state.
	 * @param array<string, mixed> $scan        This tick's scan.
	 * @return array<string, mixed>
	 */
	private function merge_scan( array $accumulated, array $scan ): array {
		$calls = is_array( $accumulated['calls'] ?? null ) ? $accumulated['calls'] : array();

		foreach ( (array) ( $scan['calls'] ?? array() ) as $group => $names ) {
			if ( ! isset( $calls[ $group ] ) ) {
				$calls[ $group ] = array();
			}

			foreach ( (array) $names as $name ) {
				if ( ! in_array( $name, $calls[ $group ], true ) ) {
					$calls[ $group ][] = $name;
				}
			}
		}

		return array(
			'calls'           => $calls,
			'prose'           => array(),
			'files'           => (int) ( $accumulated['files'] ?? 0 ) + (int) ( $scan['files'] ?? 0 ),
			'files_found'     => (int) ( $scan['files_found'] ?? 0 ),
			'bytes'           => (int) ( $scan['bytes'] ?? 0 ),
			'skipped_memory'  => (int) ( $accumulated['skipped_memory'] ?? 0 ) + (int) ( $scan['skipped_memory'] ?? 0 ),
			'skipped_large'   => (int) ( $scan['skipped_large'] ?? 0 ),
			'unparseable'     => (int) ( $accumulated['unparseable'] ?? 0 ) + (int) ( $scan['unparseable'] ?? 0 ),
			'truncated'       => ! empty( $accumulated['truncated'] ) || ! empty( $scan['truncated'] ),
			'stop_reason'     => $scan['stop_reason'] ?? ( $accumulated['stop_reason'] ?? null ),
			'entries_written' => (int) ( $accumulated['entries_written'] ?? 0 ),
		);
	}

	/**
	 * Slugs seen registering abilities, which sort to the front of the queue.
	 *
	 * @return array<int, string>
	 */
	private function ability_bearing_slugs(): array {
		$slugs = array();

		/*
		 * The recorder stores ability name => the file that registered it. The
		 * file is resolved to a plugin here rather than guessed at: a file that
		 * belongs to no plugin — core, an mu-plugin, a shared library — resolves
		 * to null and contributes nothing, exactly as in attribution.
		 */
		foreach ( $this->origins->all() as $file ) {
			$slug = $this->locator->plugin_for_file( (string) $file );

			if ( null !== $slug && ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Decide which copy of a doubly-installed plugin to extract.
	 *
	 * This site carries `ast-pro` and `advanced-shipment-tracking-pro`: one
	 * product, two directories, both active. Extracting both spends the budget
	 * twice to produce two near-identical sets of entries that disagree only in
	 * their slug.
	 *
	 * @param array<string, array<string, mixed>> $active Active plugins.
	 * @return array<int, string> Slugs to skip.
	 */
	private function resolve_duplicates( array $active ): array {
		$skip = array();

		foreach ( $this->locator->group_duplicates( $active ) as $slugs ) {
			usort(
				$slugs,
				static function ( string $a, string $b ) use ( $active ): int {
					$version = version_compare(
						(string) ( $active[ $b ]['Version'] ?? '0' ),
						(string) ( $active[ $a ]['Version'] ?? '0' )
					);

					return 0 !== $version ? $version : strcmp( $a, $b );
				}
			);

			// The newest version wins; ties break on slug so the choice is stable.
			array_shift( $slugs );

			foreach ( $slugs as $slug ) {
				$skip[] = $slug;
			}
		}

		return $skip;
	}
}
