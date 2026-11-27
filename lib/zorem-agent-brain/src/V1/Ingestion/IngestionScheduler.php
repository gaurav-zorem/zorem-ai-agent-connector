<?php
/**
 * What starts an extraction tick.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Extraction\PluginLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks the orchestrator to WordPress, and to nothing that runs on a page load.
 *
 * The rule this class exists to enforce: activation *enqueues*, cron
 * *extracts*. Reading a plugin's source during the activation request would put
 * a multi-second token scan inside a click an administrator is waiting on, and
 * on WooCommerce it would put a fatal there instead. So every trigger here does
 * the same cheap thing — write a job — and the work happens later, in a request
 * nobody is watching.
 */
final class IngestionScheduler {

	public const EVENT = 'zorem_agent_brain_extract_tick';

	/**
	 * Orchestrator.
	 *
	 * @var IngestionOrchestrator
	 */
	private IngestionOrchestrator $orchestrator;

	/**
	 * Plugin locator, for turning plugin files into slugs.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Constructor.
	 *
	 * @param IngestionOrchestrator $orchestrator Orchestrator.
	 * @param PluginLocator         $locator      Plugin locator.
	 */
	public function __construct( IngestionOrchestrator $orchestrator, PluginLocator $locator ) {
		$this->orchestrator = $orchestrator;
		$this->locator      = $locator;
	}

	/**
	 * Hook the triggers.
	 */
	public function register(): void {
		add_action( self::EVENT, array( $this, 'run_tick' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );

		add_action( 'activated_plugin', array( $this, 'on_activated' ) );
		add_action( 'deactivated_plugin', array( $this, 'on_deactivated' ) );
		add_action( 'deleted_plugin', array( $this, 'on_deleted' ), 10, 2 );
		add_action( 'upgrader_process_complete', array( $this, 'on_upgraded' ), 10, 2 );
	}

	/**
	 * Keep the tick scheduled.
	 *
	 * Checked on every request rather than only at activation, so a site that
	 * lost its cron entries recovers on its own. The event is the kit's, so
	 * whichever host runs first schedules it and a second host finds it there.
	 */
	public function ensure_scheduled(): void {
		if ( wp_next_scheduled( self::EVENT ) ) {
			return;
		}

		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::EVENT );
	}

	/**
	 * Remove the scheduled event. Called on deactivation.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::EVENT );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::EVENT );
			$timestamp = wp_next_scheduled( self::EVENT );
		}
	}

	/**
	 * Run one tick.
	 *
	 * @return array<string, mixed>
	 */
	public function run_tick(): array {
		$report = $this->orchestrator->run_tick( 'cron' );

		$this->chain( $report );

		return $report;
	}

	/**
	 * Queue another tick straight away when there is still work.
	 *
	 * ## Why
	 *
	 * The hourly schedule is the *idle* rate, and it was being used as the
	 * working rate. A tick reads `ExtractionQueue::TICK_FILES` files — 150,
	 * deliberately small because hosts differ by an order of magnitude in what
	 * they let a cron request allocate. One tick an hour therefore reads 150
	 * files an hour, and a site with a dozen plugins takes days to finish
	 * something the host could have done in minutes.
	 *
	 * Measured on a real install: five plugins in twenty-four hours, which is
	 * exactly 150 files an hour and not a fault anywhere. The schedule was the
	 * only thing wrong.
	 *
	 * So while the queue has open work, the next tick is booked a minute out
	 * rather than an hour. The hourly event stays exactly as it is and remains
	 * the safety net that restarts a site whose chain was broken by a fatal.
	 *
	 * ## Why this is safe on a small host
	 *
	 * The tick budget is unchanged — this makes ticks *more frequent*, not
	 * bigger, so peak memory per request is what it always was. Work stops the
	 * moment the queue is empty, so an idle site schedules nothing. And
	 * `run_tick()` takes a lock and exits immediately if it is held, so a slow
	 * tick cannot be overlapped by the one chained behind it.
	 *
	 * @param array<string, mixed> $report The tick report.
	 */
	private function chain( array $report ): void {
		/*
		 * A deny-list, not an allow-list, and deliberately so. `run_tick()`
		 * reports at least six statuses — idle, gone, skipped_unchanged,
		 * partial and more — and an allow-list written from the two or three
		 * that came to mind would silently stop chaining the moment a status
		 * was added or renamed. The failure would look exactly like the bug
		 * this method exists to fix, with nothing on screen to say why.
		 *
		 * `locked` means another tick is already working and will chain when
		 * it finishes; chaining here would just queue work for the lock to
		 * refuse. `disabled` means extraction is off entirely.
		 */
		if ( in_array( (string) ( $report['status'] ?? '' ), array( 'locked', 'disabled' ), true ) ) {
			return;
		}

		$summary = $report['queue']['summary'] ?? array();

		$pending = (int) ( $summary[ ExtractionQueue::STATUS_QUEUED ] ?? 0 )
			+ (int) ( $summary[ ExtractionQueue::STATUS_RUNNING ] ?? 0 )
			+ (int) ( $summary[ ExtractionQueue::STATUS_PARTIAL ] ?? 0 );

		if ( $pending < 1 ) {
			return;
		}

		/*
		 * One at a time. `wp_next_scheduled()` finds an already-booked single
		 * event for this hook, and without the check a site that ran two ticks
		 * close together would book two chains and drift into running them in
		 * parallel — which the lock would then spend its time refusing.
		 */
		if ( wp_next_scheduled( self::EVENT ) > time() + MINUTE_IN_SECONDS ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::EVENT );
		}
	}

	/**
	 * A plugin was activated.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 */
	public function on_activated( $plugin_file ): void {
		$this->orchestrator->activated( $this->locator->slug_from_plugin_file( (string) $plugin_file ) );
	}

	/**
	 * A plugin was deactivated.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 */
	public function on_deactivated( $plugin_file ): void {
		$this->orchestrator->deactivated( $this->locator->slug_from_plugin_file( (string) $plugin_file ) );
	}

	/**
	 * A plugin was deleted.
	 *
	 * @param string $plugin_file Plugin file, relative to the plugins directory.
	 * @param bool   $deleted     Whether its files were removed.
	 */
	public function on_deleted( $plugin_file, $deleted = true ): void {
		if ( ! $deleted ) {
			return;
		}

		$this->orchestrator->deleted( $this->locator->slug_from_plugin_file( (string) $plugin_file ) );
	}

	/**
	 * Plugins were updated or installed.
	 *
	 * The cursor is reset for these: an update changes the file list a resume
	 * point indexes into, so continuing an old pass would stitch together facts
	 * from two releases.
	 *
	 * @param mixed                $upgrader Upgrader instance.
	 * @param array<string, mixed> $extra    What was processed.
	 */
	public function on_upgraded( $upgrader, $extra ): void {
		unset( $upgrader );

		if ( ! is_array( $extra ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}

		$plugins = array();

		if ( isset( $extra['plugins'] ) && is_array( $extra['plugins'] ) ) {
			$plugins = $extra['plugins'];
		} elseif ( isset( $extra['plugin'] ) ) {
			$plugins = array( $extra['plugin'] );
		}

		foreach ( $plugins as $plugin_file ) {
			$this->orchestrator->enqueue(
				$this->locator->slug_from_plugin_file( (string) $plugin_file ),
				'upgraded',
				true
			);
		}
	}
}
