<?php
/**
 * Whether a plugin is already covered by curated knowledge.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The skip-scan check, consulted before a plugin is ever queued.
 *
 * Curated knowledge — hand-written, version-ranged, better than anything a
 * token scan can produce — makes local extraction redundant for the plugins it
 * covers. The check therefore belongs in front of the queue, not behind it:
 * extracting WooCommerce and then discarding the result would spend the most
 * expensive pass on this site to produce nothing.
 *
 * Two properties matter more than the feature itself:
 *
 * 1. With the catalog setting off, no code path here reaches the network. Not
 *    "the request fails", not "the response is ignored" — the adapter is not
 *    asked. A site that has not opted in makes no outbound request, and that is
 *    a claim a test can hold us to.
 * 2. Absence of coverage is never inferred from a failure. An adapter that
 *    errors, times out, or is missing reports "not covered", so the plugin goes
 *    into the local queue. Failing the other way would let a network blip erase
 *    a plugin from the Brain silently.
 *
 * The adapter is the bundled registry (FixtureCuratedKbAdapter). This is the
 * seam it plugs into, built so the ordering rule is enforced by structure
 * rather than remembered.
 */
final class CuratedCoverage {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The curated adapter, when one is wired.
	 *
	 * @var object|null
	 */
	private ?object $adapter;

	/**
	 * Constructor.
	 *
	 * @param Settings    $settings Plugin settings.
	 * @param object|null $adapter  Curated KB adapter, when one is registered.
	 */
	public function __construct( Settings $settings, ?object $adapter = null ) {
		$this->settings = $settings;
		$this->adapter  = $adapter;
	}

	/**
	 * Why the last lookup failed, when it did.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Why the last curated lookup failed, or an empty string.
	 */
	/**
	 * The installed version of a plugin, for the catalog's range check.
	 *
	 * Read from WordPress rather than passed in, because the caller that asks
	 * "is this covered" has a slug and nothing else. An unknown version is
	 * returned as an empty string, which no range matches — so an unrecognised
	 * plugin falls to local extraction rather than being claimed by a catalog
	 * entry that may not describe it.
	 *
	 * @param string $slug Plugin directory name.
	 */
	private function installed_version( string $slug ): string {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( dirname( $file ) === $slug ) {
				return (string) ( $data['Version'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * Why the last coverage lookup failed, or '' when it did not.
	 */
	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * Whether the curated catalog may be consulted at all.
	 */
	public function is_enabled(): bool {
		return $this->settings->bool( 'knowledge_curated_kb' );
	}

	/**
	 * Slugs the curated catalog covers, out of those offered.
	 *
	 * @param array<int, string> $slugs Candidate slugs.
	 * @return array<int, string>
	 */
	public function covered( array $slugs ): array {
		if ( array() === $slugs ) {
			return array();
		}

		/*
		 * The early return is the compliance guarantee. Everything past it may
		 * talk to a remote service; nothing before it can.
		 */
		if ( ! $this->is_enabled() ) {
			return array();
		}

		if ( null === $this->adapter || ! method_exists( $this->adapter, 'covered_slugs' ) ) {
			return array();
		}

		$this->last_error = '';

		try {
			$covered = $this->adapter->covered_slugs( $slugs );

			/*
			 * Covered means "has entries for the installed version", not "the
			 * catalog mentions this plugin".
			 *
			 * `covered_slugs()` answers from the index alone. Extraction was
			 * then skipped for every slug it named — so a plugin the catalog
			 * lists but has nothing usable for was dropped from the queue and
			 * given nothing in its place. The screen said COVERED, 0 entries
			 * ingested, and the site's own documentation for that plugin
			 * stopped being extracted.
			 *
			 * Verified against a real install: WooCommerce 11.0.1 sat inside
			 * the catalog's 10.9-11.99 range and the checksums matched, so this
			 * particular plugin does yield entries — but nothing was ever
			 * storing them, which is the second half of the same failure. The
			 * check below is what makes the claim testable either way.
			 *
			 * Failing toward local extraction is the rule this class already
			 * states for errors; it now applies to emptiness too, which is the
			 * same situation arrived at more quietly.
			 */
			$covered = array_values(
				array_filter(
					$covered,
					function ( $slug ) {
						return array() !== $this->adapter->fetch(
							(string) $slug,
							$this->installed_version( (string) $slug )
						);
					}
				)
			);
		} catch ( \Throwable $e ) {
			/*
			 * Fail toward local extraction. A plugin wrongly treated as curated
			 * is a plugin missing from the Brain with nothing to show why.
			 *
			 * Broad, because this calls a remote adapter, but recorded: an empty
			 * result here is indistinguishable from "the catalog covers nothing"
			 * unless the reason is kept, and a catalog that is quietly failing
			 * every time looks exactly like a catalog that is switched off.
			 */
			$this->last_error = $e->getMessage();

			return array();
		}

		if ( ! is_array( $covered ) ) {
			return array();
		}

		// A plugin the administrator keeps on this site's own knowledge is not covered: it is extracted.
		return array_values(
			array_diff(
				array_intersect( $slugs, array_map( 'strval', $covered ) ),
				\ZoremAgentBrain\V1\Curated\CuratedOverrides::all()
			)
		);
	}

	/**
	 * Replace the adapter. Used by Brain's wiring and by tests.
	 *
	 * @param object|null $adapter Adapter.
	 */
	public function set_adapter( ?object $adapter ): void {
		$this->adapter = $adapter;
	}
}
