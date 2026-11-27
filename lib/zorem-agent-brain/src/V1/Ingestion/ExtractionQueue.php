<?php
/**
 * The extraction work queue and its per-plugin resume cursors.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Extraction\PluginLocator;

defined( 'ABSPATH' ) || exit;

/**
 * What is waiting to be extracted, and how far each one got.
 *
 * Stored as a single option rather than a table. The queue is bounded by the
 * number of active plugins — dozens, not thousands — and one option read per
 * tick is cheaper than a schema migration mid-phase. Autoload is off: this is
 * written on every tick and read by nothing else.
 *
 * The cursor is the point of this class. A pass that dies at file 800 of 1500
 * must resume at 801: restarting at 0 means running into the same wall forever,
 * and skipping to the next plugin means a silent gap in the Brain that nothing
 * downstream can detect. Both failures look like success from the outside,
 * which is why the cursor is persisted before the work rather than after it.
 */
final class ExtractionQueue {

	public const OPTION = 'zorem_agent_brain_extraction_queue';

	public const STATUS_QUEUED  = 'queued';
	public const STATUS_RUNNING = 'running';
	public const STATUS_PARTIAL = 'partial';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/**
	 * How many ticks one plugin may consume before it is abandoned.
	 *
	 * Without a cap, a plugin that cannot finish inside this host's memory would
	 * hold the head of the queue forever and every plugin behind it would starve
	 * — a Brain that silently stops growing, with a cron job burning a tick every
	 * five minutes to achieve nothing. At the cap the plugin lands in `partial`
	 * with its truncation recorded, and the queue moves on.
	 *
	 * Twelve ticks at 150 files each covers the 1,500-file budget with room for
	 * ticks lost to the memory guard.
	 */
	public const MAX_TICKS = 12;

	/**
	 * How many times a plugin may be retried after an outright failure.
	 *
	 * A fatal persists nothing, so the only evidence it happened is that the
	 * cursor did not move. Attempts are counted at claim time for exactly that
	 * reason — see `claim()`.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * Files one tick may read.
	 */
	public const TICK_FILES = 150;

	/**
	 * Plugin locator, for sizing.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Constructor.
	 *
	 * @param PluginLocator|null $locator Plugin locator.
	 */
	public function __construct( ?PluginLocator $locator = null ) {
		$this->locator = $locator ?? new PluginLocator();
	}

	/**
	 * Every job, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$jobs = get_option( self::OPTION, array() );

		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * One job.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		$jobs = $this->all();

		return isset( $jobs[ $slug ] ) && is_array( $jobs[ $slug ] ) ? $jobs[ $slug ] : null;
	}

	/**
	 * Add a plugin to the queue, or refresh an existing job.
	 *
	 * Idempotent by slug. An existing job's cursor is preserved unless the
	 * caller asks for a reset — a second `activated_plugin` for a plugin already
	 * half-extracted should not throw away 800 files of progress.
	 *
	 * @param string               $slug   Plugin slug.
	 * @param string               $reason Why it was enqueued.
	 * @param bool                 $reset  Discard any existing cursor.
	 * @param array<string, mixed> $hints  Ordering hints, e.g. has_abilities.
	 */
	public function enqueue( string $slug, string $reason = 'manual', bool $reset = false, array $hints = array() ): void {
		if ( '' === $slug ) {
			return;
		}

		$jobs     = $this->all();
		$existing = $jobs[ $slug ] ?? null;

		if ( is_array( $existing ) && ! $reset ) {
			/*
			 * Already queued and mid-pass: leave the cursor alone. Only the
			 * hints are refreshed, since ability registration may have been
			 * observed since the job was created.
			 */
			if ( in_array( $existing['status'] ?? '', array( self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_PARTIAL ), true ) ) {
				$jobs[ $slug ] = array_merge( $existing, $this->hints( $slug, $hints ) );
				$this->save( $jobs );

				return;
			}
		}

		$jobs[ $slug ] = array_merge(
			array(
				'slug'            => $slug,
				'reason'          => $reason,
				'status'          => self::STATUS_QUEUED,
				'enqueued_at'     => time(),
				'started_at'      => 0,
				'file_offset'     => 0,
				'extractor_index' => 0,
				'ticks'           => 0,
				'attempts'        => 0,
				'checksum'        => '',
				'scan'            => array(),
				'last_error'      => '',
			),
			$this->hints( $slug, $hints )
		);

		$this->save( $jobs );
	}

	/**
	 * Bring the queue back in line with what is actually active.
	 *
	 * Enqueueing happens from four different hooks and is not itself locked, so
	 * a lost write is possible. This sweep runs each tick and repairs it: a
	 * missing job reappears, a job for a plugin that is no longer active is
	 * dropped. The queue therefore self-heals rather than depending on every
	 * enqueue having won its race.
	 *
	 * @param array<string, array<string, mixed>> $active        Active plugins, slug => data.
	 * @param array<int, string>                  $ability_slugs Slugs seen registering abilities.
	 * @param array<string, string>               $checksums     Current checksum per slug.
	 * @return array{added: array<int, string>, removed: array<int, string>, refreshed: array<int, string>}
	 */
	public function reconcile( array $active, array $ability_slugs = array(), array $checksums = array() ): array {
		$jobs   = $this->all();
		$report = array(
			'added'     => array(),
			'removed'   => array(),
			'refreshed' => array(),
		);

		foreach ( array_keys( $jobs ) as $slug ) {
			if ( ! isset( $active[ $slug ] ) ) {
				unset( $jobs[ $slug ] );
				$report['removed'][] = (string) $slug;
			}
		}

		foreach ( array_keys( $active ) as $slug ) {
			$slug = (string) $slug;

			if ( ! isset( $jobs[ $slug ] ) ) {
				continue;
			}

			/*
			 * A finished job whose plugin has since changed goes back in the
			 * queue. This is what makes an update visible without waiting for
			 * `upgrader_process_complete` to have fired — a plugin updated by
			 * FTP, by Git, or by a deploy script fires no hook at all.
			 */
			$done    = self::STATUS_DONE === ( $jobs[ $slug ]['status'] ?? '' );
			$changed = isset( $checksums[ $slug ] ) && (string) ( $jobs[ $slug ]['checksum'] ?? '' ) !== $checksums[ $slug ];

			if ( $done && $changed ) {
				unset( $jobs[ $slug ] );
				$report['refreshed'][] = $slug;
			}
		}

		$this->save( $jobs );

		foreach ( array_keys( $active ) as $slug ) {
			$slug = (string) $slug;

			if ( null !== $this->get( $slug ) ) {
				continue;
			}

			$this->enqueue(
				$slug,
				in_array( $slug, $report['refreshed'], true ) ? 'changed' : 'reconcile',
				false,
				array( 'has_abilities' => in_array( $slug, $ability_slugs, true ) )
			);

			$report['added'][] = $slug;
		}

		return $report;
	}

	/**
	 * The next job to work on, or null when there is nothing to do.
	 *
	 * Order, most significant first:
	 *
	 * 1. Work already started. A half-extracted plugin finishes before a new one
	 *    begins, or its cursor would sit at 800 indefinitely while fresh jobs
	 *    kept overtaking it.
	 * 2. Ability-bearing plugins. Measured as by far the highest value
	 *    per entry: they describe tools an agent can actually call.
	 * 3. Smallest first. A small host gets the maximum number of plugins into
	 *    the Brain before the queue reaches the one that may never finish.
	 *
	 * @return array<string, mixed>|null
	 */
	public function next(): ?array {
		$jobs = $this->all();
		$open = array();

		foreach ( $jobs as $slug => $job ) {
			if ( ! is_array( $job ) ) {
				continue;
			}

			if ( ! in_array( $job['status'] ?? '', array( self::STATUS_QUEUED, self::STATUS_RUNNING ), true ) ) {
				continue;
			}

			$job['slug'] = (string) $slug;
			$open[]      = $job;
		}

		if ( array() === $open ) {
			return null;
		}

		usort(
			$open,
			static function ( array $a, array $b ): int {
				$a_started = ( (int) ( $a['file_offset'] ?? 0 ) > 0 || (int) ( $a['extractor_index'] ?? 0 ) > 0 ) ? 0 : 1;
				$b_started = ( (int) ( $b['file_offset'] ?? 0 ) > 0 || (int) ( $b['extractor_index'] ?? 0 ) > 0 ) ? 0 : 1;

				if ( $a_started !== $b_started ) {
					return $a_started <=> $b_started;
				}

				$a_abilities = empty( $a['has_abilities'] ) ? 1 : 0;
				$b_abilities = empty( $b['has_abilities'] ) ? 1 : 0;

				if ( $a_abilities !== $b_abilities ) {
					return $a_abilities <=> $b_abilities;
				}

				$size = (int) ( $a['size'] ?? 0 ) <=> (int) ( $b['size'] ?? 0 );

				if ( 0 !== $size ) {
					return $size;
				}

				// Slug last, so the order is total and the tests are deterministic.
				return strcmp( (string) $a['slug'], (string) $b['slug'] );
			}
		);

		return $open[0];
	}

	/**
	 * Mark a job as being worked on, counting the attempt.
	 *
	 * The attempt is counted here, before the work, not after it. A pass killed
	 * by a fatal never reaches any "after"; if attempts were counted on
	 * completion, the one failure mode the cap exists to stop would be the one
	 * it could not see.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $checksum Checksum of the plugin as claimed.
	 * @return array<string, mixed>|null The claimed job, or null when it is gone.
	 */
	public function claim( string $slug, string $checksum = '' ): ?array {
		$jobs = $this->all();

		if ( ! isset( $jobs[ $slug ] ) || ! is_array( $jobs[ $slug ] ) ) {
			return null;
		}

		$job = $jobs[ $slug ];

		$job['status']   = self::STATUS_RUNNING;
		$job['attempts'] = (int) ( $job['attempts'] ?? 0 ) + 1;
		$job['ticks']    = (int) ( $job['ticks'] ?? 0 ) + 1;

		if ( 0 === (int) ( $job['started_at'] ?? 0 ) ) {
			$job['started_at'] = time();
		}

		if ( '' !== $checksum ) {
			$job['checksum'] = $checksum;
		}

		$jobs[ $slug ] = $job;
		$this->save( $jobs );

		return $job;
	}

	/**
	 * Persist a resume point after a tick that did not finish the plugin.
	 *
	 * @param string               $slug            Plugin slug.
	 * @param int                  $file_offset     Next file index to read.
	 * @param int                  $extractor_index Next extractor to run.
	 * @param array<string, mixed> $scan            Accumulated scan state.
	 */
	public function save_cursor( string $slug, int $file_offset, int $extractor_index, array $scan = array() ): void {
		$jobs = $this->all();

		if ( ! isset( $jobs[ $slug ] ) || ! is_array( $jobs[ $slug ] ) ) {
			return;
		}

		$jobs[ $slug ]['file_offset']     = max( 0, $file_offset );
		$jobs[ $slug ]['extractor_index'] = max( 0, $extractor_index );
		$jobs[ $slug ]['scan']            = $scan;
		$jobs[ $slug ]['status']          = self::STATUS_QUEUED;

		/*
		 * Progress resets the attempt counter. The cap exists to stop a plugin
		 * that cannot move, not to punish one that needs several ticks — three
		 * fatals in a row is a wall, three fatals across a long successful pass
		 * is a bad afternoon.
		 */
		$jobs[ $slug ]['attempts'] = 0;

		$this->save( $jobs );
	}

	/**
	 * Mark a job finished.
	 *
	 * @param string               $slug  Plugin slug.
	 * @param array<string, mixed> $stats Summary to keep for the UI.
	 */
	public function complete( string $slug, array $stats = array() ): void {
		$jobs = $this->all();

		if ( ! isset( $jobs[ $slug ] ) || ! is_array( $jobs[ $slug ] ) ) {
			return;
		}

		$jobs[ $slug ]['status']       = self::STATUS_DONE;
		$jobs[ $slug ]['completed_at'] = time();
		$jobs[ $slug ]['stats']        = $stats;
		$jobs[ $slug ]['scan']         = array();
		$jobs[ $slug ]['last_error']   = '';

		$this->save( $jobs );
	}

	/**
	 * Give up on a job, permanently, and say why.
	 *
	 * `partial` is not a failure state that hides: whatever was extracted stays,
	 * and the reason is recorded on the job so the admin screen can show that
	 * this plugin's knowledge is known-incomplete rather than merely thin.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $status Terminal status.
	 * @param string $reason Why.
	 */
	public function terminate( string $slug, string $status, string $reason ): void {
		$jobs = $this->all();

		if ( ! isset( $jobs[ $slug ] ) || ! is_array( $jobs[ $slug ] ) ) {
			return;
		}

		$jobs[ $slug ]['status']       = $status;
		$jobs[ $slug ]['last_error']   = $reason;
		$jobs[ $slug ]['completed_at'] = time();
		$jobs[ $slug ]['scan']         = array();

		$this->save( $jobs );
	}

	/**
	 * Whether a job has run out of road.
	 *
	 * @param array<string, mixed> $job Job record.
	 */
	public function is_exhausted( array $job ): bool {
		return (int) ( $job['ticks'] ?? 0 ) >= self::MAX_TICKS
			|| (int) ( $job['attempts'] ?? 0 ) > self::MAX_ATTEMPTS;
	}

	/**
	 * Remove a job.
	 *
	 * @param string $slug Plugin slug.
	 */
	public function remove( string $slug ): void {
		$jobs = $this->all();

		if ( ! isset( $jobs[ $slug ] ) ) {
			return;
		}

		unset( $jobs[ $slug ] );

		$this->save( $jobs );
	}

	/**
	 * Empty the queue.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Counts by status, for the admin screen and for reporting.
	 *
	 * @return array<string, int>
	 */
	public function summary(): array {
		$counts = array(
			self::STATUS_QUEUED  => 0,
			self::STATUS_RUNNING => 0,
			self::STATUS_PARTIAL => 0,
			self::STATUS_DONE    => 0,
			self::STATUS_FAILED  => 0,
		);

		foreach ( $this->all() as $job ) {
			$status = is_array( $job ) ? (string) ( $job['status'] ?? '' ) : '';

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}

	/**
	 * Ordering hints for a job.
	 *
	 * @param string               $slug  Plugin slug.
	 * @param array<string, mixed> $hints Caller-supplied hints.
	 * @return array<string, mixed>
	 */
	private function hints( string $slug, array $hints ): array {
		$size = $hints['size'] ?? null;

		if ( null === $size ) {
			$measured = $this->locator->measure( $slug );
			$size     = $measured['bytes'];
		}

		return array(
			'has_abilities' => (bool) ( $hints['has_abilities'] ?? false ),
			'size'          => (int) $size,
		);
	}

	/**
	 * Write the queue back.
	 *
	 * @param array<string, array<string, mixed>> $jobs Jobs.
	 */
	private function save( array $jobs ): void {
		update_option( self::OPTION, $jobs, false );
	}
}
