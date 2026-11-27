<?php
/**
 * Knowledge persistence.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Store;

use ZoremAgentBrain\V1\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * All database access for knowledge entries.
 *
 * Ported from the legacy plugin. Every query is prepared; table names come
 * from Installer constants, never from input. Only KnowledgeGate reads through
 * this class on behalf of a reader — nothing that serves a reader may call it
 * directly.
 */
final class KnowledgeRepository {

	/**
	 * Per-request cache of entries, keyed by entry_key.
	 *
	 * @var array<string, KnowledgeEntry>
	 */
	private array $cache = array();

	/**
	 * The knowledge table name.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_KNOWLEDGE );
	}

	/**
	 * Find one entry by its stable key.
	 *
	 * @param string $entry_key Entry key.
	 */
	public function find( string $entry_key ): ?KnowledgeEntry {
		if ( isset( $this->cache[ $entry_key ] ) ) {
			return $this->cache[ $entry_key ];
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is internal; value is bound.
			$wpdb->prepare( 'SELECT * FROM %i WHERE entry_key = %s', $table, $entry_key ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * Find one entry by key, ignoring the cache.
	 *
	 * @param string $entry_key Entry key.
	 */
	private function find_uncached( string $entry_key ): ?KnowledgeEntry {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is internal; value is bound.
			$wpdb->prepare( 'SELECT * FROM %i WHERE entry_key = %s', $table, $entry_key ),
			ARRAY_A
		);

		return is_array( $row ) && ! empty( $row['id'] ) ? new KnowledgeEntry( $row ) : null;
	}

	/**
	 * Find one entry by row id.
	 *
	 * @param int $id Row id.
	 */
	public function find_by_id( int $id ): ?KnowledgeEntry {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * Query entries.
	 *
	 * @param array<string, mixed> $args {
	 *     Filters.
	 *
	 *     @type string             $plugin_slug  Restrict to one plugin.
	 *     @type string             $section      Restrict to one section.
	 *     @type array<int, string> $sections     Restrict to several sections.
	 *     @type string             $source_type  Restrict to one source type.
	 *     @type string             $trust_status approved or draft.
	 *     @type bool               $include_stale Include entries whose plugin is inactive. Default false.
	 *     @type array<int, string> $entry_keys   Restrict to specific keys.
	 *     @type int                $per_page     Rows per page. Default 50, maximum 500.
	 *     @type int                $page         Page number, one-based.
	 * }
	 * @return array<string, mixed> Keys: items, total.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$table    = $this->table();
		$per_page = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where_sql, $params, $from ) = $this->build_where( $args );

		$list_params = array_merge( $params, array( $per_page, $offset ) );

		/*
		 * Suppression justified, sniff by sniff, for the list and count
		 * statements below and nothing else: a custom table with no WP API
		 * (DirectDatabaseQuery, NoCaching); `$table` from Installer and
		 * `$where_sql` assembled only from literal fragments in this file, with
		 * every caller value bound (InterpolatedNotPrepared, NotPrepared); and
		 * the array form of prepare(), whose placeholders the sniff cannot count
		 * through a variable (PreparedSQLPlaceholders.*).
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$table}.* FROM {$from} WHERE {$where_sql} ORDER BY plugin_slug ASC, section ASC, priority ASC LIMIT %d OFFSET %d",
				$list_params
			),
			ARRAY_A
		);

		/*
		 * The WHERE clause is always applied, even with nothing to bind: a
		 * paramless clause can still carry meaning (a reader denied everything is
		 * a literal "1=0"), and dropping it would silently serve stale knowledge.
		 */
		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}";
		$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, $params ) : $count_sql );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->fail_scoped_read_on_error( $args );

		$items = array();

		foreach ( (array) $rows as $row ) {
			$entry = $this->hydrate( $row );

			if ( $entry instanceof KnowledgeEntry ) {
				$items[] = $entry;
			}
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Build a WHERE clause and its bound parameters.
	 *
	 * Every fragment here is a literal; all caller input is bound.
	 *
	 * Returns the FROM clause too, so the callers stay unchanged should a filter
	 * ever need a join again.
	 *
	 * @param array<string, mixed> $args Filters.
	 * @return array{0: string, 1: array<int, mixed>, 2: string}
	 */
	private function build_where( array $args ): array {
		$where  = array( '1=1' );
		$params = array();
		$from   = $this->table();

		if ( ! empty( $args['plugin_slug'] ) ) {
			$where[]  = 'plugin_slug = %s';
			$params[] = (string) $args['plugin_slug'];
		}

		if ( ! empty( $args['section'] ) ) {
			$where[]  = 'section = %s';
			$params[] = (string) $args['section'];
		}

		if ( ! empty( $args['sections'] ) && is_array( $args['sections'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['sections'] ), '%s' ) );
			$where[]      = "section IN ( {$placeholders} )";

			foreach ( $args['sections'] as $section ) {
				$params[] = (string) $section;
			}
		}

		if ( ! empty( $args['entry_keys'] ) && is_array( $args['entry_keys'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $args['entry_keys'] ), '%s' ) );
			$where[]      = "entry_key IN ( {$placeholders} )";

			foreach ( $args['entry_keys'] as $key ) {
				$params[] = (string) $key;
			}
		}

		if ( ! empty( $args['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$params[] = (string) $args['source_type'];
		}

		if ( ! empty( $args['trust_status'] ) ) {
			$where[]  = 'trust_status = %s';
			$params[] = (string) $args['trust_status'];
		}

		if ( ! empty( $args['reader_scope'] ) && is_array( $args['reader_scope'] ) ) {
			list( $scope_sql, $scope_params ) = $this->build_reader_scope( $args['reader_scope'] );

			$where[] = $scope_sql;
			$params  = array_merge( $params, $scope_params );
		}

		/*
		 * Superseded entries lost a precedence contest — a curated entry
		 * describes the same plugin and section better. They are retained so
		 * that removing the winner restores them, but they must not be served
		 * or counted while a winner exists.
		 */
		if ( empty( $args['include_superseded'] ) ) {
			$where[]  = '( meta IS NULL OR meta NOT LIKE %s )';
			$params[] = '%"superseded_by":"%';
		}

		/*
		 * Stale entries belong to deactivated plugins. They are retained so
		 * reactivation is cheap, but they describe software that is not running
		 * and must not be served unless something explicitly asks for them.
		 *
		 * The pattern is bound rather than inlined: a literal % inside a string
		 * passed to $wpdb->prepare() is read as a format specifier and mangled.
		 */
		if ( empty( $args['include_stale'] ) ) {
			$where[]  = '( meta IS NULL OR meta NOT LIKE %s )';
			$params[] = '%"stale":true%';
		}

		return array( implode( ' AND ', $where ), $params, $from );
	}

	/**
	 * Search entries, scoped to what the caller may read.
	 *
	 * The scope constraints go into this query, not around it. Ranking and
	 * counting both happen over the filtered set, so an agent cannot learn that
	 * a better match exists somewhere it may not look — neither by seeing a
	 * total that does not match its results, nor by watching its own results
	 * shuffle as unreadable entries change.
	 *
	 * FULLTEXT is used when the index exists and LIKE otherwise. There is one
	 * more fallback than that suggests: on MyISAM, natural-language FULLTEXT
	 * discards any word appearing in more than half the rows, so a small
	 * knowledge base can return nothing at all for its most obvious term. A
	 * FULLTEXT search that finds nothing therefore retries with LIKE rather than
	 * reporting an empty Brain.
	 *
	 * @param SearchQuery          $query Normalised query.
	 * @param array<string, mixed> $args  Same filters as query(), plus per_page/page.
	 * @return array{items: array<int, KnowledgeEntry>, total: int, strategy: string}
	 */
	public function search( SearchQuery $query, array $args = array() ): array {
		$empty = array(
			'items'    => array(),
			'total'    => 0,
			'strategy' => 'none',
		);

		if ( $query->is_empty() ) {
			return $empty;
		}

		if ( self::has_knowledge_fulltext_index_cached() ) {
			$result = $this->run_search( $query, $args, true );

			if ( $result['total'] > 0 ) {
				return $result;
			}
		}

		return $this->run_search( $query, $args, false );
	}

	/**
	 * Run one search strategy.
	 *
	 * @param SearchQuery          $query    Normalised query.
	 * @param array<string, mixed> $args     Filters.
	 * @param bool                 $fulltext Whether to use the FULLTEXT index.
	 * @return array{items: array<int, KnowledgeEntry>, total: int, strategy: string}
	 */
	private function run_search( SearchQuery $query, array $args, bool $fulltext ): array {
		global $wpdb;

		$table    = $this->table();
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		list( $where_sql, $params, $from ) = $this->build_where( $args );

		$order_params = array();

		if ( $fulltext ) {
			/*
			 * Natural-language mode, never boolean. See SearchQuery: boolean
			 * operators would let an agent probe the shape of knowledge it
			 * cannot read by watching result counts move.
			 */
			$match = 'MATCH( title, summary, content ) AGAINST ( %s )';

			$where_sql = $where_sql . ' AND ' . $match;
			$params[]  = $query->normalized();

			/*
			 * Ranking repeats the MATCH in ORDER BY rather than selecting it as
			 * a `relevance` column. A placeholder in the SELECT list would be
			 * emitted before every WHERE placeholder, so its value would have to
			 * be prepended to the parameter list — correct only for as long as
			 * two separate pieces of code stay in agreement. In ORDER BY the
			 * placeholder comes last in the string and its value is appended
			 * last, which is the same rule the rest of this class follows.
			 */
			$order          = 'ORDER BY ' . $match . ' DESC, priority ASC';
			$order_params[] = $query->normalized();
		} else {
			$terms = $query->terms();

			if ( array() === $terms ) {
				return array(
					'items'    => array(),
					'total'    => 0,
					'strategy' => 'none',
				);
			}

			$clauses = array();

			foreach ( $terms as $term ) {
				/*
				 * esc_like() first, then the wildcards. Reversed, the % and _
				 * we add would themselves be escaped and the search would match
				 * only exact strings.
				 */
				$like = '%' . $wpdb->esc_like( $term ) . '%';

				$clauses[] = '( title LIKE %s OR summary LIKE %s OR content LIKE %s )';

				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			/*
			 * Terms are ANDed. FULLTEXT's natural-language mode ranks partial
			 * matches, so an OR there is useful; an unranked LIKE fallback ORed
			 * together returns everything containing any word, which for a
			 * two-word query is close to returning the whole Brain. AND keeps
			 * the fallback's results recognisably the same search.
			 */
			$where_sql = $where_sql . ' AND ( ' . implode( ' AND ', $clauses ) . ' )';
			$order     = 'ORDER BY priority ASC, updated_at DESC';
		}

		/*
		 * Parameters in emission order: WHERE, then ORDER BY, then LIMIT. The
		 * statement below is written in exactly that sequence, and this is the
		 * only place the two orders have to agree.
		 */
		$list_params = array_merge( $params, $order_params, array( $per_page, $offset ) );

		/*
		 * Suppression justified, sniff by sniff, for the two statements below
		 * and nothing else:
		 *
		 * - DirectDatabaseQuery / NoCaching: a custom table with no WP API and
		 *   no cache layer of its own; the per-request cache lives in this class.
		 * - InterpolatedNotPrepared / NotPrepared: `$table` comes from
		 *   Installer, `$where_sql` and `$order` are built only from literal
		 *   fragments in this file, and every caller value is bound.
		 * - PreparedSQLPlaceholders.*: the array form of prepare() is the
		 *   documented vsprintf-style call, and the sniff cannot count
		 *   placeholders through a variable.
		 *
		 * The parameter order is the load-bearing part and it is asserted by
		 * test, not by this comment — see the left-to-right rule above.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$table}.* FROM {$from} WHERE {$where_sql} {$order} LIMIT %d OFFSET %d",
				$list_params
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$from} WHERE {$where_sql}", $params )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->fail_scoped_read_on_error( $args );

		$items = array();

		foreach ( (array) $rows as $row ) {
			$entry = $this->hydrate( $row );

			if ( $entry instanceof KnowledgeEntry ) {
				$items[] = $entry;
			}
		}

		return array(
			'items'    => $items,
			'total'    => $total,
			'strategy' => $fulltext ? 'fulltext' : 'like',
		);
	}

	/**
	 * Whether the FULLTEXT index exists, asked once per request.
	 */
	private static function has_knowledge_fulltext_index_cached(): bool {
		static $has = null;

		if ( null === $has ) {
			$has = Installer::has_fulltext_index();
		}

		return $has;
	}

	/**
	 * Section coverage for a set of entries the caller may read.
	 *
	 * @param array<string, mixed> $args Same filters as query().
	 * @return array<int, array<string, mixed>> Rows of plugin_slug, section, entries, tokens.
	 */
	public function topics( array $args = array() ): array {
		global $wpdb;

		$table = $this->table();

		list( $where_sql, $params, $from ) = $this->build_where( $args );

		/*
		 * Same justification as query(): custom table, literal fragments, every
		 * caller value bound, array-form prepare(). Scope is the one aggregate
		 * statement below.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = "SELECT plugin_slug, section, COUNT(*) AS entries, SUM( token_estimate ) AS tokens
			FROM {$from} WHERE {$where_sql}
			GROUP BY plugin_slug, section
			ORDER BY plugin_slug ASC, section ASC";

		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, $params ) : $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$this->fail_scoped_read_on_error( $args );

		return (array) $rows;
	}

	/**
	 * Throw when a reader-scoped read hit a database error.
	 *
	 * An erroring statement returns no rows, which reads exactly like an empty
	 * Brain — and an empty Brain is a plausible answer. A scoped read is one a
	 * reader is waiting on, so the failure is raised for KnowledgeGate to
	 * record as `resolver_error` rather than served as silence. Unscoped
	 * maintenance reads keep the old behaviour.
	 *
	 * @param array<string, mixed> $args The read's arguments.
	 * @throws \RuntimeException When the last statement failed.
	 */
	private function fail_scoped_read_on_error( array $args ): void {
		global $wpdb;

		if ( ! empty( $args['reader_scope'] ) && '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Scoped knowledge read failed: ' . esc_html( (string) $wpdb->last_error ) );
		}
	}

	/**
	 * Turn a reader's scope constraints into a WHERE fragment.
	 *
	 * The nesting mirrors ReaderScopeResolver::decide() level for level —
	 * entry, section, plugin, then derived access — each level's allow winning
	 * over every broader level's deny. One nested expression rather than ANDed
	 * conditions because the precedence *is* the rule.
	 *
	 * It runs inside the query so that `total` counts only what the reader may
	 * read; a count over rows it cannot open is a disclosure in its own right.
	 *
	 * @param array<string, mixed> $scope Constraints from the resolver.
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_reader_scope( array $scope ): array {
		if ( ! empty( $scope['deny_all'] ) ) {
			return array( '1=0', array() );
		}

		$params = array();

		$in = function ( string $column, array $values ) use ( &$params ): string {
			if ( array() === $values ) {
				// An empty set matches nothing; IN () is not valid SQL.
				return '1=0';
			}

			foreach ( $values as $value ) {
				$params[] = (string) $value;
			}

			return $column . ' IN ( ' . implode( ', ', array_fill( 0, count( $values ), '%s' ) ) . ' )';
		};

		/*
		 * A null derived_sections — an administrator's reader — is a literal
		 * 1=1, any section; an empty list is 1=0, none.
		 */
		$derived_sections = array_key_exists( 'derived_sections', $scope ) && null === $scope['derived_sections']
			? null
			: (array) ( $scope['derived_sections'] ?? array() );

		/*
		 * Built strictly left to right, in the order the placeholders appear in
		 * the finished string, because $wpdb->prepare() binds positionally
		 * (PATTERNS 4). Each $in() call appends its values as it is evaluated,
		 * and PHP evaluates this concatenation left to right.
		 */
		$sql = '( ' . $in( 'entry_key', (array) ( $scope['allow_entries'] ?? array() ) )
			. ' OR ( NOT ' . $in( 'entry_key', (array) ( $scope['deny_entries'] ?? array() ) )
			. ' AND ( ' . $in( 'section', (array) ( $scope['allow_sections'] ?? array() ) )
			. ' OR ( NOT ' . $in( 'section', (array) ( $scope['deny_sections'] ?? array() ) )
			. ' AND ( ' . $in( 'plugin_slug', (array) ( $scope['allow_plugins'] ?? array() ) )
			. ' OR ( NOT ' . $in( 'plugin_slug', (array) ( $scope['deny_plugins'] ?? array() ) )
			. ' AND ( ( ' . $in( 'plugin_slug', (array) ( $scope['derived_plugins'] ?? array() ) )
			. ' AND ' . ( null === $derived_sections ? '1=1' : $in( 'section', $derived_sections ) )
			. " ) OR ( COALESCE( plugin_slug, '' ) = '' AND " . $in( 'source_type', (array) ( $scope['store_sources'] ?? array() ) )
			. ' AND ' . $in( 'section', (array) ( $scope['public_sections'] ?? array() ) )
			. ' ) ) ) ) ) ) ) )';

		return array( $sql, $params );
	}

	/**
	 * Insert or update an entry, keyed on entry_key.
	 *
	 * @param KnowledgeEntry|array<string, mixed> $entry Entry or entry data.
	 * @return int|\WP_Error Row id.
	 */
	public function upsert( $entry ) {
		$data = $entry instanceof KnowledgeEntry ? $entry->to_array() : $entry;

		$entry_key = (string) ( $data['entry_key'] ?? '' );

		if ( '' === $entry_key ) {
			return new \WP_Error(
				'zorem_agent_brain_entry_missing_key',
				__( 'A knowledge entry must have an entry key.', 'zorem-ai-agent-connector' )
			);
		}

		$source_type = (string) ( $data['source_type'] ?? KnowledgeEntry::SOURCE_PLUGIN_EXTRACTED );

		if ( ! in_array( $source_type, KnowledgeEntry::source_types(), true ) ) {
			return new \WP_Error(
				'zorem_agent_brain_entry_invalid_source',
				__( 'Unknown knowledge source type.', 'zorem-ai-agent-connector' )
			);
		}

		global $wpdb;

		$now     = current_time( 'mysql', true );
		$content = (string) ( $data['content'] ?? '' );
		$summary = (string) ( $data['summary'] ?? '' );

		unset( $this->cache[ $entry_key ] );

		/*
		 * The insert-or-update decision reads the database directly rather than
		 * the cache. A cached entry proves a row was there when it was read, not
		 * that it is there now — and if it has since been deleted, taking the
		 * update branch quietly writes the entry nowhere while returning an id
		 * that looks like success. Caches are for reads.
		 */
		$existing = $this->find_uncached( $entry_key );

		$row = array(
			'entry_key'      => $entry_key,
			'source_type'    => $source_type,
			'source_ref'     => isset( $data['source_ref'] ) ? (string) $data['source_ref'] : null,
			'plugin_slug'    => isset( $data['plugin_slug'] ) ? (string) $data['plugin_slug'] : null,
			'plugin_version' => isset( $data['plugin_version'] ) ? (string) $data['plugin_version'] : null,
			'section'        => (string) ( $data['section'] ?? KnowledgeEntry::SECTION_OVERVIEW ),
			'title'          => (string) ( $data['title'] ?? $entry_key ),
			'summary'        => $summary,
			'content'        => $content,
			'facts'          => wp_json_encode( (array) ( $data['facts'] ?? array() ) ),
			'priority'       => isset( $data['priority'] )
				? (int) $data['priority']
				: KnowledgeEntry::default_priority( $source_type ),
			'checksum'       => isset( $data['checksum'] ) ? (string) $data['checksum'] : md5( $content . $summary ),
			'token_estimate' => KnowledgeEntry::estimate_tokens( $content . ' ' . $summary ),
			'updated_at'     => $now,
			'meta'           => wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
		);

		$row['trust_status'] = $this->resolve_trust_status( $data, $existing, (string) $row['checksum'], $source_type );

		if ( null === $existing ) {
			$row['created_at'] = $now;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert( $this->table(), $row );

			if ( false === $inserted ) {
				return new \WP_Error(
					'zorem_agent_brain_entry_insert_failed',
					__( 'The knowledge entry could not be saved.', 'zorem-ai-agent-connector' )
				);
			}

			return (int) $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( false === $wpdb->update( $this->table(), $row, array( 'id' => $existing->get_id() ) ) ) {
			return new \WP_Error(
				'zorem_agent_brain_entry_update_failed',
				__( 'The knowledge entry could not be updated.', 'zorem-ai-agent-connector' )
			);
		}

		return $existing->get_id();
	}

	/**
	 * Decide an entry's trust status on write.
	 *
	 * Two rules, both carried from the legacy plugin:
	 *
	 * 1. An administrator's approval decision survives re-ingestion. Extraction
	 *    running again must not silently promote or demote what a human decided.
	 * 2. Generated content that has *changed* returns to draft. An approval
	 *    applies to the text that was read, not to the entry key forever.
	 *
	 * @param array<string, mixed> $data        Incoming data.
	 * @param KnowledgeEntry|null  $existing    Existing entry, when there is one.
	 * @param string               $checksum    Checksum of the incoming content.
	 * @param string               $source_type Incoming source type.
	 */
	private function resolve_trust_status( array $data, ?KnowledgeEntry $existing, string $checksum, string $source_type ): string {
		if ( null === $existing ) {
			// AI output is never trusted on arrival; everything else is.
			if ( isset( $data['trust_status'] ) ) {
				return (string) $data['trust_status'];
			}

			return KnowledgeEntry::SOURCE_AI_GENERATED === $source_type
				? KnowledgeEntry::TRUST_DRAFT
				: KnowledgeEntry::TRUST_APPROVED;
		}

		if ( KnowledgeEntry::SOURCE_AI_GENERATED === $source_type && $existing->get_checksum() !== $checksum ) {
			return KnowledgeEntry::TRUST_DRAFT;
		}

		return $existing->get_trust_status();
	}

	/**
	 * Set an entry's trust status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status approved or draft.
	 */
	public function set_trust_status( int $id, string $status ): bool {
		if ( ! in_array( $status, array( KnowledgeEntry::TRUST_APPROVED, KnowledgeEntry::TRUST_DRAFT ), true ) ) {
			return false;
		}

		global $wpdb;

		$this->cache = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			$this->table(),
			array(
				'trust_status' => $status,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Entry counts by plugin, section, source and trust, for the admin screens.
	 *
	 * An administrator's view of the whole store — not a reader's. Nothing
	 * that serves a reader may use it; KnowledgeGate is their door.
	 *
	 * @return array<int, array{plugin: string, section: string, source: string, trust: string, entries: int, tokens: int}>
	 */
	public function coverage(): array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; no input.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT COALESCE( plugin_slug, '' ) AS plugin, section, source_type AS source, trust_status AS trust, COUNT(*) AS entries, COALESCE( SUM( token_estimate ), 0 ) AS tokens FROM %i GROUP BY plugin, section, source, trust ORDER BY plugin ASC, section ASC", $table ), ARRAY_A );

		return array_map(
			static fn ( array $row ): array => array(
				'plugin'  => (string) $row['plugin'],
				'section' => (string) $row['section'],
				'source'  => (string) $row['source'],
				'trust'   => (string) $row['trust'],
				'entries' => (int) $row['entries'],
				'tokens'  => (int) $row['tokens'],
			),
			(array) $rows
		);
	}

	/**
	 * Every plugin slug that has knowledge, for an administrator's reader.
	 *
	 * @return array<int, string>
	 */
	public function plugin_slugs(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table; no input.
		$slugs = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT plugin_slug FROM %i WHERE plugin_slug IS NOT NULL AND plugin_slug <> ''", $this->table() ) );

		return array_values( array_map( 'strval', (array) $slugs ) );
	}

	/**
	 * Mark every entry for a plugin stale, or clear the mark.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param bool   $stale       Whether the plugin's knowledge is stale.
	 * @return int Entries updated.
	 */
	public function set_plugin_stale( string $plugin_slug, bool $stale ): int {
		/*
		 * Merged, not replaced: each entry's metadata is re-read from the
		 * database inside merge_meta(), so a concurrent write to another key —
		 * provenance, a superseded flag — is not reverted by this pass. The
		 * legacy version rebuilt the whole meta array from this query's
		 * results, which is the lost-update shape merge_meta() exists to avoid.
		 */
		/*
		 * Superseded entries too: they belong to the plugin like the rest, and
		 * leaving them out made plugin_stale_summary() count entries this pass
		 * could never change, so the reconciliation would retry them forever.
		 */
		$result  = $this->query(
			array(
				'plugin_slug'        => $plugin_slug,
				'include_stale'      => true,
				'include_superseded' => true,
				'per_page'           => 500,
			)
		);
		$updated = 0;

		foreach ( $result['items'] as $entry ) {
			if ( $this->merge_meta( $entry->get_id(), array( 'stale' => $stale ? true : null ) ) ) {
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * For every plugin with entries, how many entries it has and how many are stale.
	 *
	 * Entries that belong to no plugin (the merchant's own pages) are left out:
	 * nothing about plugin activation applies to them.
	 *
	 * @return array<string, array{entries: int, stale: int}> Plugin slug => counts.
	 */
	public function plugin_stale_summary(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Internal table; the pattern is bound, not inlined.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT plugin_slug, COUNT(*) AS entries, SUM( CASE WHEN meta LIKE %s THEN 1 ELSE 0 END ) AS stale FROM %i WHERE plugin_slug IS NOT NULL AND plugin_slug <> '' GROUP BY plugin_slug", '%"stale":true%', $this->table() ), ARRAY_A );

		$summary = array();

		foreach ( (array) $rows as $row ) {
			$summary[ (string) $row['plugin_slug'] ] = array(
				'entries' => (int) $row['entries'],
				'stale'   => (int) $row['stale'],
			);
		}

		return $summary;
	}

	/**
	 * Merge changes into an entry's metadata.
	 *
	 * The merge base is read from the database, not from the cache and not from
	 * an entry the caller happens to be holding. Both of those describe the row
	 * as it was when it was read, so merging onto them writes back whatever else
	 * has changed since — the same lost-update shape as deciding an insert from
	 * a cached row.
	 *
	 * @param int                  $id      Row id.
	 * @param array<string, mixed> $changes Keys to set.
	 */
	public function merge_meta( int $id, array $changes ): bool {
		$existing = $this->find_by_id( $id );

		if ( ! $existing instanceof KnowledgeEntry ) {
			return false;
		}

		$merged = array_merge( $existing->get_meta(), $changes );

		/*
		 * A null value removes the key rather than storing a null. Callers use
		 * this to clear a flag — `superseded_by => null` when an entry stops
		 * being superseded — and a key left behind holding null reads as
		 * present to anything checking with array_key_exists().
		 */
		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $merged[ $key ] );
			}
		}

		return $this->update_meta( $id, $merged );
	}

	/**
	 * Replace an entry's metadata wholesale.
	 *
	 * Replaces — it does not merge. A caller passing a partial array silently
	 * discards everything else the entry recorded, provenance included. Use
	 * `merge_meta()` unless replacement is genuinely what is wanted.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $meta Metadata.
	 */
	public function update_meta( int $id, array $meta ): bool {
		global $wpdb;

		$this->cache = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			$this->table(),
			array(
				'meta'       => wp_json_encode( $meta ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Delete every entry for a plugin.
	 *
	 * Used when a plugin is uninstalled, not merely deactivated.
	 *
	 * @param string $plugin_slug Plugin slug.
	 */
	public function delete_for_plugin( string $plugin_slug ): bool {
		global $wpdb;

		$this->cache = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'plugin_slug' => $plugin_slug ), array( '%s' ) );
	}

	/**
	 * Delete one entry.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$this->cache = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Turn a row into an entry, caching it.
	 *
	 * @param array<string, mixed>|null $row Database row.
	 */
	private function hydrate( ?array $row ): ?KnowledgeEntry {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return null;
		}

		$entry = new KnowledgeEntry( $row );

		$this->cache[ $entry->get_entry_key() ] = $entry;

		return $entry;
	}

	/**
	 * Clear the per-request cache.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}
}
