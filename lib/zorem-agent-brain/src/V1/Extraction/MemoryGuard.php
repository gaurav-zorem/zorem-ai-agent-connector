<?php
/**
 * Memory headroom guard for extraction.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Stops extraction before it exhausts the process, rather than after.
 *
 * Measured on the corpus this plugin was built against: WordPress with eleven
 * active plugins loaded already occupies 110 MB of a 128 MB limit, leaving
 * 18 MB of headroom. Token-scanning WooCommerce needs roughly 20 MB more, so
 * the process died — no output, no error, exit 255. A shared host on the
 * default 128 MB would see the same thing, and the admin would see a blank
 * screen with nothing in the log to explain it.
 *
 * File and byte budgets do not help here: memory pressure comes from how much
 * WordPress and its plugins have already loaded before extraction starts,
 * which no per-plugin budget can predict. So headroom is checked as the scan
 * runs, and running out is treated the same way as any other budget — stop,
 * record why, and let the caller surface it. A partial Brain that says it is
 * partial is worth far more than a fatal.
 */
final class MemoryGuard {

	/**
	 * Stop when free memory falls below this many bytes.
	 *
	 * Set from measurement, not taste. At 12 MB the guard let WooCommerce
	 * through and the process still died: a single token array overshot the
	 * estimate and there was nothing left to absorb it. Extraction stopping a
	 * little early costs a resumable pass; stopping a little late costs the
	 * whole request, with WordPress's fatal handler showing the admin a white
	 * screen and no usable reason.
	 */
	private const MIN_HEADROOM_BYTES = 25165824;

	/**
	 * How much larger a token array is than the source it came from.
	 *
	 * Measured on this corpus rather than assumed: WooCommerce's
	 * class-woocommerce.php expands 45.7x, wc-core-functions.php 56.9x, and
	 * Elementor's controls-stack.php 48.6x. Sixty is above the observed range
	 * with room to spare, because being wrong in the other direction means a
	 * fatal rather than a skipped file.
	 *
	 * This is why a headroom check alone is not enough: at this ratio a single
	 * 900 KB file needs about 50 MB, which no plausible headroom covers. The
	 * decision has to be per file, against the memory actually available at
	 * that moment.
	 */
	private const TOKEN_MEMORY_MULTIPLIER = 60;

	/**
	 * Treat an unlimited or unreadable limit as this, for headroom maths.
	 */
	private const ASSUMED_LIMIT_BYTES = 268435456;

	/**
	 * The process memory limit in bytes, or null when unlimited.
	 *
	 * @var int|null
	 */
	private ?int $limit;

	/**
	 * Bytes of headroom to preserve.
	 *
	 * @var int
	 */
	private int $headroom;

	/**
	 * Constructor.
	 *
	 * @param int|null $headroom_bytes Override the required headroom.
	 */
	public function __construct( ?int $headroom_bytes = null ) {
		$this->limit    = self::limit_bytes();
		$this->headroom = $headroom_bytes ?? self::MIN_HEADROOM_BYTES;
	}

	/**
	 * Whether extraction should stop now.
	 *
	 * Collects cycles first, then measures. Without the collection step this
	 * latched permanently: token arrays from a large plugin left the arena
	 * expanded, and since PHP never returns allocated chunks to the OS every
	 * later plugin saw the same exhausted headroom and scanned nothing. The
	 * result looked like a memory limit and was actually a measurement bug.
	 */
	public function should_stop(): bool {
		if ( $this->available_bytes() >= $this->headroom ) {
			return false;
		}

		// Second opinion: reclaim what is genuinely dead, then re-measure.
		gc_collect_cycles();

		return $this->available_bytes() < $this->headroom;
	}

	/**
	 * Free memory in bytes, for diagnostics.
	 *
	 * Measured against live data rather than the allocated arena. PHP satisfies
	 * new allocations from freed blocks inside the arena before growing it, so
	 * live usage is what determines whether the next token array will fit.
	 */
	public function available_bytes(): int {
		$limit = $this->limit ?? self::ASSUMED_LIMIT_BYTES;

		/*
		 * Measured against the allocated arena, not live data. PHP enforces
		 * memory_limit on real allocation, so that is the number the process
		 * will actually die on. Live usage looks healthier and would let the
		 * guard wave through an allocation the engine then refuses.
		 *
		 * A consequence worth stating plainly: once a large plugin has grown
		 * the arena, headroom stays low for the rest of the process, because
		 * PHP does not return chunks to the OS. That is not a measurement
		 * artefact — it is the real constraint, and it is why extraction runs
		 * one plugin per cron tick rather than sweeping every plugin in a
		 * single request.
		 */
		return max( 0, $limit - memory_get_usage( true ) );
	}

	/**
	 * Whether a file of this size can be tokenised with the memory available.
	 *
	 * @param int $file_bytes Source size.
	 */
	public function can_scan( int $file_bytes ): bool {
		return ( $file_bytes * self::TOKEN_MEMORY_MULTIPLIER ) < $this->available_bytes();
	}

	/**
	 * The largest file that can currently be tokenised.
	 */
	public function max_scannable_bytes(): int {
		return (int) floor( $this->available_bytes() / self::TOKEN_MEMORY_MULTIPLIER );
	}

	/**
	 * The configured limit in bytes, or null when unlimited.
	 */
	public static function limit_bytes(): ?int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );

		if ( '' === $raw || '-1' === $raw ) {
			return null;
		}

		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;

		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * A diagnostics snapshot.
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(): array {
		return array(
			'limit_bytes'     => $this->limit,
			'live_bytes'      => memory_get_usage( false ),
			'allocated_bytes' => memory_get_usage( true ),
			'peak_bytes'      => memory_get_peak_usage( true ),
			'available_bytes' => $this->available_bytes(),
			'headroom_bytes'  => $this->headroom,
		);
	}
}
