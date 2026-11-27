<?php
/**
 * Decides which of several entries about the same thing is served.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves competing entries within one plugin and section.
 *
 * Ported from the legacy plugin. Several sources can describe the same
 * thing; lower priority wins (see KnowledgeEntry::default_priority()).
 *
 * **Losers are retained, not deleted.** That is the whole design. Deleting the
 * extracted entry when a curated one arrives would make switching the curated
 * source off destructive: the site would be left with a hole where knowledge
 * used to be, and rebuilding it costs a full extraction pass. Instead the loser
 * is flagged `meta.superseded_by`, hidden from agents exactly as stale entries
 * are, and un-flagged the moment the winner goes away.
 *
 * The flag lives on the row rather than being computed per query because
 * retrieval has to filter on it in SQL — a count that included superseded rows
 * would be wrong in the same way a count including unreadable rows is wrong.
 */
final class Precedence {

	/**
	 * Knowledge storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Knowledge storage.
	 */
	public function __construct( KnowledgeRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Recompute precedence for one plugin.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @return array{winners: int, superseded: int, restored: int}
	 */
	public function resolve( string $plugin_slug ): array {
		$result = $this->repository->query(
			array(
				'plugin_slug'        => $plugin_slug,
				'per_page'           => 500,
				'include_stale'      => true,
				'include_superseded' => true,
			)
		);

		$by_section = array();

		foreach ( $result['items'] as $entry ) {
			$by_section[ $entry->get_section() ][] = $entry;
		}

		$stats = array(
			'winners'    => 0,
			'superseded' => 0,
			'restored'   => 0,
		);

		foreach ( $by_section as $entries ) {
			$winner = null;

			foreach ( $entries as $entry ) {
				/*
				 * Drafts never win. An unreviewed generated entry must not
				 * supersede knowledge a human approved, and since drafts are
				 * invisible to agents anyway, letting one win would hide the
				 * real entry behind something nobody can read.
				 */
				if ( KnowledgeEntry::TRUST_APPROVED !== $entry->get_trust_status() ) {
					continue;
				}

				if ( null === $winner || $entry->get_priority() < $winner->get_priority() ) {
					$winner = $entry;
				}
			}

			foreach ( $entries as $entry ) {
				$meta     = $entry->get_meta();
				$is_loser = null !== $winner && $entry->get_id() !== $winner->get_id()
					&& KnowledgeEntry::TRUST_APPROVED === $entry->get_trust_status();

				/*
				 * Flagged *by the current winner*, not merely flagged.
				 *
				 * This read `! empty( $meta['superseded_by'] )`, so an entry
				 * that already carried a flag was left exactly as it was — and
				 * the name in it was never corrected. Once a winner changed, or
				 * once a wrong name got in there by any route, the screen went
				 * on saying "Superseded by <something else>" forever, which is
				 * the one thing this field exists not to do: the whole point of
				 * naming the replacement is that the reader can go and find it.
				 *
				 * On this install six entries pointed at another plugin's
				 * entry as their replacement and no rebuild could shift them,
				 * because every rebuild saw "already flagged" and moved on.
				 */
				$flagged_by = (string) ( $meta['superseded_by'] ?? '' );

				if ( $is_loser && $flagged_by !== $winner->get_entry_key() ) {
					$this->repository->merge_meta(
						$entry->get_id(),
						array( 'superseded_by' => $winner->get_entry_key() )
					);

					++$stats['superseded'];
					continue;
				}

				if ( ! $is_loser && '' !== $flagged_by ) {
					$this->repository->merge_meta(
						$entry->get_id(),
						array( 'superseded_by' => null )
					);

					++$stats['restored'];
				}
			}

			if ( null !== $winner ) {
				++$stats['winners'];
			}
		}

		return $stats;
	}
}
