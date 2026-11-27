<?php
/**
 * The one place knowledge access is decided and recorded.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Gate;

use ZoremAgentBrain\V1\Scope\Reader;
use ZoremAgentBrain\V1\Scope\ReaderScopeResolver;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;
use ZoremAgentBrain\V1\Store\SearchQuery;
use ZoremAgentBridge\V1\Bridge;
use ZoremAgentBridge\V1\Dto\AuditEvent;

defined( 'ABSPATH' ) || exit;

/**
 * Everything that serves knowledge to a reader goes through here.
 *
 * Ported from the legacy KnowledgeGate. Reads are filtered in SQL by the
 * resolver's constraints, then each row is confirmed individually — the query
 * is a filter, this is the authority, and both are built from the same
 * precedence.
 *
 * **The catches here are not swallows.** Every one converts a failure into the
 * only safe outcome — a denial — and records it as `resolver_error`. The rest
 * of the scoping path has no catches at all, so a failure there propagates to
 * this boundary instead of becoming a plausible-looking answer (PATTERNS 2).
 *
 * Denials are recorded through the bridge's audit sink — AI Agent OS's
 * activity log when it is installed. When it is not, the
 * `zorem_agent_brain_unrecorded_denial` action carries the event to the host's
 * own log, and any denial nobody records is counted, so silence is visible.
 */
final class KnowledgeGate {

	/** Logged when scope resolution itself failed. */
	public const RESOLVER_ERROR = 'resolver_error';

	/** The action name recorded against knowledge denials. */
	public const READ_ACTION = 'agent-brain/knowledge-read';

	/** Owner name on audit events. */
	public const SOURCE = 'zorem-agent-brain';

	/**
	 * Scope resolution.
	 *
	 * @var ReaderScopeResolver
	 */
	private ReaderScopeResolver $resolver;

	/**
	 * Knowledge storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Denials no log accepted this request.
	 *
	 * @var int
	 */
	private int $unrecorded = 0;

	/**
	 * Constructor.
	 *
	 * @param ReaderScopeResolver $resolver   Scope resolution.
	 * @param KnowledgeRepository $repository Knowledge storage.
	 */
	public function __construct( ReaderScopeResolver $resolver, KnowledgeRepository $repository ) {
		$this->resolver   = $resolver;
		$this->repository = $repository;
	}

	/**
	 * Whether a reader may read an entry, recording the denial if not.
	 *
	 * @param Reader         $reader The reader.
	 * @param KnowledgeEntry $entry  The entry.
	 */
	public function can_read( Reader $reader, KnowledgeEntry $entry ): bool {
		try {
			$decision = $this->resolver->decide( $reader, $entry );
		} catch ( \Throwable $e ) {
			$this->record_denial( $reader, $entry->get_entry_key(), self::RESOLVER_ERROR, $e );

			return false;
		}

		if ( ! $decision['allowed'] ) {
			$this->record_denial( $reader, $entry->get_entry_key(), $decision['reason'] );

			return false;
		}

		return true;
	}

	/**
	 * One entry by key, or null when it is not readable.
	 *
	 * Null covers both "no such entry" and "not yours": a reader that could
	 * tell them apart could enumerate the Brain one key at a time.
	 *
	 * @param Reader $reader    The reader.
	 * @param string $entry_key Entry key.
	 */
	public function get( Reader $reader, string $entry_key ): ?KnowledgeEntry {
		try {
			$entry = $this->repository->find( $entry_key );
		} catch ( \Throwable $e ) {
			$this->record_denial( $reader, $entry_key, self::RESOLVER_ERROR, $e );

			return null;
		}

		if ( ! $entry instanceof KnowledgeEntry ) {
			return null;
		}

		return $this->can_read( $reader, $entry ) ? $entry : null;
	}

	/**
	 * Query knowledge as a reader sees it. `total` counts readable rows only.
	 *
	 * @param Reader               $reader The reader.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array{items: array<int, KnowledgeEntry>, total: int}
	 */
	public function query( Reader $reader, array $args = array() ): array {
		$empty = array(
			'items' => array(),
			'total' => 0,
		);

		try {
			$result = $this->repository->query( $this->scoped( $reader, $args ) );
		} catch ( \Throwable $e ) {
			$this->record_denial( $reader, '*', self::RESOLVER_ERROR, $e );

			return $empty;
		}

		return array(
			'items' => $this->confirm( $reader, $result['items'] ),
			'total' => (int) $result['total'],
		);
	}

	/**
	 * Search knowledge as a reader sees it. Ranking and total cover readable rows only.
	 *
	 * @param Reader               $reader The reader.
	 * @param SearchQuery          $query  Normalised query.
	 * @param array<string, mixed> $args   Query arguments.
	 * @return array{items: array<int, KnowledgeEntry>, total: int, strategy: string}
	 */
	public function search( Reader $reader, SearchQuery $query, array $args = array() ): array {
		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'strategy' => 'none',
		);

		try {
			$result = $this->repository->search( $query, $this->scoped( $reader, $args ) );
		} catch ( \Throwable $e ) {
			$this->record_denial( $reader, '*', self::RESOLVER_ERROR, $e );

			return $empty;
		}

		return array(
			'items'    => $this->confirm( $reader, $result['items'] ),
			'total'    => (int) $result['total'],
			'strategy' => (string) $result['strategy'],
		);
	}

	/**
	 * Section coverage as a reader sees it.
	 *
	 * @param Reader $reader The reader.
	 * @return array<int, array<string, mixed>>
	 */
	public function topics( Reader $reader ): array {
		try {
			return $this->repository->topics( $this->scoped( $reader, array() ) );
		} catch ( \Throwable $e ) {
			$this->record_denial( $reader, '*', self::RESOLVER_ERROR, $e );

			return array();
		}
	}

	/**
	 * Denials no log accepted this request.
	 */
	public function unrecorded_denials(): int {
		return $this->unrecorded;
	}

	/**
	 * Apply the reader's scope, overriding any caller-supplied trust or staleness filter.
	 *
	 * Overwritten, not merged: drafts and stale entries are invisible through
	 * every path, and a path that takes those filters from the caller is one
	 * that can be asked not to apply them.
	 *
	 * @param Reader               $reader The reader.
	 * @param array<string, mixed> $args   Caller arguments.
	 * @return array<string, mixed>
	 */
	private function scoped( Reader $reader, array $args ): array {
		$args['trust_status']       = KnowledgeEntry::TRUST_APPROVED;
		$args['include_stale']      = false;
		$args['include_superseded'] = false;
		$args['reader_scope']       = $this->resolver->query_constraints( $reader );

		return $args;
	}

	/**
	 * Confirm each row with the resolver; the authority wins over the filter.
	 *
	 * @param Reader                     $reader  The reader.
	 * @param array<int, KnowledgeEntry> $entries Rows from the filtered query.
	 * @return array<int, KnowledgeEntry>
	 */
	private function confirm( Reader $reader, array $entries ): array {
		return array_values(
			array_filter(
				$entries,
				fn ( KnowledgeEntry $entry ): bool => $this->can_read( $reader, $entry )
			)
		);
	}

	/**
	 * Record a denial.
	 *
	 * @param Reader          $reader    The reader.
	 * @param string          $entry_key Entry key, or '*' for a whole query.
	 * @param string          $reason    Reason slug.
	 * @param \Throwable|null $error     The failure, when there was one.
	 */
	private function record_denial( Reader $reader, string $entry_key, string $reason, ?\Throwable $error = null ): void {
		/*
		 * The class and message, not the trace: the activity screen is readable
		 * by administrators, and a stack trace carries paths and argument values.
		 */
		$summary = null === $error ? '' : get_class( $error ) . ': ' . $error->getMessage();

		$event = AuditEvent::from_array(
			array(
				'agent_ref'     => $reader->ref(),
				'source_plugin' => self::SOURCE,
				'channel'       => 'knowledge',
				'action'        => self::READ_ACTION,
				'object_type'   => 'knowledge_entry',
				'object_id'     => $entry_key,
				'outcome'       => AuditEvent::OUTCOME_DENIED,
				'reason'        => $reason,
				'input_summary' => $summary,
			)
		);

		/**
		 * Lets the plugin that owns a reader record its denials in its own log.
		 *
		 * Return true once the denial is recorded. AI Shopping Assistant records
		 * its `exp:*` readers' denials in its own activity log, whether or not AI
		 * Agent OS runs, so neither plugin's log depends on the other.
		 *
		 * @param bool       $recorded Whether a listener already recorded it.
		 * @param AuditEvent $event    The denial.
		 * @param Reader     $reader   The reader that was refused.
		 */
		if ( true === apply_filters( 'zorem_agent_brain_record_denial', false, $event, $reader ) ) {
			return;
		}

		if ( Bridge::audit()->record( $event ) ) {
			return;
		}

		if ( has_action( 'zorem_agent_brain_unrecorded_denial' ) ) {
			/**
			 * Fires for a knowledge denial no shared activity log accepted.
			 *
			 * AI Shopping Assistant, running without AI Agent OS, records these
			 * in its own activity log.
			 *
			 * @param AuditEvent $event The denial.
			 */
			do_action( 'zorem_agent_brain_unrecorded_denial', $event );

			return;
		}

		++$this->unrecorded;
	}
}
