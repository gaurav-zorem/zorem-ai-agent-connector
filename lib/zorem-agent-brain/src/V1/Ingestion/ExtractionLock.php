<?php
/**
 * Mutual exclusion for extraction passes.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

defined( 'ABSPATH' ) || exit;

/**
 * Ensures only one extraction pass runs at a time.
 *
 * Four separate things can start a pass: the cron tick, plugin activation,
 * the upgrader finishing, and the Rebuild button. Two of them overlapping on
 * the same plugin would double-write entries and, worse, race on the resume
 * cursor — one pass persisting offset 400 while another persists 900 produces
 * a knowledge set with a hole in it that nothing afterwards can detect.
 *
 * The lock is `add_option()`, not `get_option()` then `update_option()`.
 * add_option fails when the row already exists, and it does so inside a single
 * INSERT, so two requests arriving together cannot both believe they won.
 * A check-then-set built from get/update has a window between the two
 * statements, and cron ticks are exactly the workload that finds such windows.
 *
 * Autoload is off: this row is written and deleted constantly and has no
 * business in the options cache on every page load.
 */
final class ExtractionLock {

	public const OPTION = 'zorem_agent_brain_extraction_lock';

	/**
	 * How long a lock survives without being released.
	 *
	 * A pass killed by a fatal — which the memory findings make a normal event,
	 * not an exception — never releases its lock. Without expiry the queue
	 * would stop permanently the first time a big plugin exhausted memory, and
	 * the only symptom would be a Brain that quietly stopped growing.
	 */
	private const TTL_SECONDS = 300;

	/**
	 * Token proving this instance owns the lock.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Try to take the lock.
	 *
	 * @return bool Whether this caller now owns it.
	 */
	public function acquire(): bool {
		global $wpdb;

		$this->release_if_expired();

		$token = wp_generate_uuid4();
		$value = maybe_serialize(
			array(
				'token'      => $token,
				'expires_at' => time() + self::TTL_SECONDS,
				'started_at' => time(),
			)
		);

		/*
		 * INSERT IGNORE is the atomic part: of two requests racing, the
		 * database lets exactly one row in. add_option() is not atomic — it
		 * reads first and writes with ON DUPLICATE KEY UPDATE, so both racers
		 * "succeed" and two passes run at once.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- An atomic claim; no API offers one.
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::OPTION, $value ) );

		self::forget_cached();

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		$this->token = $token;

		return true;
	}

	/**
	 * Drop WordPress's cached copy of the lock row, written behind its back.
	 */
	private static function forget_cached(): void {
		wp_cache_delete( self::OPTION, 'options' );

		$missing = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $missing ) && isset( $missing[ self::OPTION ] ) ) {
			unset( $missing[ self::OPTION ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
	}

	/**
	 * Release the lock, if this instance owns it.
	 *
	 * Ownership is checked by token so a caller cannot release a lock that
	 * expired and was retaken by someone else mid-pass.
	 */
	public function release(): void {
		if ( null === $this->token ) {
			return;
		}

		$held = get_option( self::OPTION );

		if ( is_array( $held ) && ( $held['token'] ?? null ) === $this->token ) {
			delete_option( self::OPTION );
		}

		$this->token = null;
	}

	/**
	 * Whether a lock is currently held by anyone.
	 */
	public function is_held(): bool {
		$this->release_if_expired();

		return false !== get_option( self::OPTION, false );
	}

	/**
	 * Whether this instance holds the lock.
	 */
	public function is_mine(): bool {
		if ( null === $this->token ) {
			return false;
		}

		$held = get_option( self::OPTION );

		return is_array( $held ) && ( $held['token'] ?? null ) === $this->token;
	}

	/**
	 * Drop a lock whose holder never came back.
	 */
	private function release_if_expired(): void {
		global $wpdb;

		self::forget_cached();

		$held = get_option( self::OPTION, false );

		if ( ! is_array( $held ) || (int) ( $held['expires_at'] ?? 0 ) > time() ) {
			return;
		}

		// Only the expired row itself: a racer that already retook the lock keeps it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Compare-and-delete; no API offers one.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::OPTION, maybe_serialize( $held ) ) );

		self::forget_cached();
	}

	/**
	 * Forcibly clear the lock. For the Rebuild button and for tests.
	 */
	public static function force_release(): void {
		delete_option( self::OPTION );
	}
}
