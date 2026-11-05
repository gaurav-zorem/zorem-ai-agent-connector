<?php
/**
 * Agent persistence.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

use AiAgentOs\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * All database access for agents.
 *
 * Every query is prepared. Table names are built from $wpdb->prefix plus a
 * class constant and never from input, so interpolating them is safe;
 * identifiers cannot be bound as placeholders in any case.
 */
final class AgentRepository {

	/**
	 * Per-request cache, keyed by agent id.
	 *
	 * @var array<int, Agent>
	 */
	private array $cache = array();

	/**
	 * The agents table name.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_AGENTS );
	}

	/**
	 * Find one agent by id.
	 *
	 * @param int $id Agent id.
	 */
	public function find( int $id ): ?Agent {
		if ( isset( $this->cache[ $id ] ) ) {
			return $this->cache[ $id ];
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * Find one agent by its public uuid.
	 *
	 * @param string $uuid Agent uuid.
	 */
	public function find_by_uuid( string $uuid ): ?Agent {
		return $this->find_by_column( 'uuid', $uuid );
	}

	/**
	 * Find one agent by stored token hash.
	 *
	 * @param string $hash HMAC of the presented token.
	 */
	public function find_by_token_hash( string $hash ): ?Agent {
		if ( '' === $hash ) {
			return null;
		}

		return $this->find_by_column( 'token_hash', $hash );
	}

	/**
	 * Find one agent by its shadow user id.
	 *
	 * @param int $user_id Shadow user id.
	 */
	public function find_by_shadow_user( int $user_id ): ?Agent {
		if ( $user_id < 1 ) {
			return null;
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE shadow_user_id = %d', $table, $user_id ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * Look one agent up by an indexed string column.
	 *
	 * @param string $column One of a fixed allow-list of column names.
	 * @param string $value  Value to match.
	 */
	private function find_by_column( string $column, string $value ): ?Agent {
		$allowed = array( 'uuid', 'slug', 'token_hash' );

		if ( ! in_array( $column, $allowed, true ) ) {
			return null;
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Column comes from the allow-list above; value is bound.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s', $table, $column, $value ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * The agent this site runs.
	 *
	 * The site has one. It is provisioned on activation and lives for as long as
	 * the plugin does: it can be renamed, revoked, reactivated and given new
	 * credentials, and it is removed with the plugin's data on uninstall. The
	 * oldest row wins so that a site which somehow holds more than one — a
	 * restored database, a half-finished migration — resolves to the same agent
	 * on every request rather than a different one each time.
	 */
	public function current(): ?Agent {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; no input.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC LIMIT 1', $table ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * Insert a new agent.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return int|\WP_Error New agent id.
	 */
	public function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$row = array(
			'uuid'             => (string) ( $data['uuid'] ?? wp_generate_uuid4() ),
			'name'             => (string) ( $data['name'] ?? '' ),
			'slug'             => (string) ( $data['slug'] ?? '' ),
			'acts_for'         => Agent::normalize_acts_for( (string) ( $data['acts_for'] ?? '' ) ),
			'status'           => (string) ( $data['status'] ?? Agent::STATUS_ACTIVE ),
			'auth_method'      => (string) ( $data['auth_method'] ?? 'bearer' ),
			'token_hash'       => isset( $data['token_hash'] ) ? (string) $data['token_hash'] : null,
			'token_prefix'     => isset( $data['token_prefix'] ) ? (string) $data['token_prefix'] : null,
			'token_created_at' => isset( $data['token_created_at'] ) ? (string) $data['token_created_at'] : null,
			'shadow_user_id'   => (int) ( $data['shadow_user_id'] ?? 0 ),
			'expires_at'       => ! empty( $data['expires_at'] ) ? (string) $data['expires_at'] : null,
			'created_by'       => (int) ( $data['created_by'] ?? get_current_user_id() ),
			'created_at'       => $now,
			'updated_at'       => $now,
			'meta'             => wp_json_encode( (array) ( $data['meta'] ?? array() ) ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$inserted = $wpdb->insert( $this->table(), $row );

		if ( false === $inserted ) {
			return new \WP_Error(
				'ai_agent_os_agent_insert_failed',
				__( 'The agent could not be saved.', 'zorem-ai-agent-connector' )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an agent.
	 *
	 * @param int                  $id   Agent id.
	 * @param array<string, mixed> $data Column values to change.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$allowed = array(
			'name',
			'slug',
			'acts_for',
			'status',
			'auth_method',
			'token_hash',
			'token_prefix',
			'token_created_at',
			'token_last_used_at',
			'shadow_user_id',
			'expires_at',
			'meta',
		);

		$row = array();

		foreach ( $data as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}

			if ( 'meta' === $key ) {
				$row[ $key ] = wp_json_encode( (array) $value );
				continue;
			}

			// Who an agent acts for decides what it may read; an unknown value is never stored.
			if ( 'acts_for' === $key && ! in_array( $value, Agent::acts_for_values(), true ) ) {
				continue;
			}

			$row[ $key ] = $value;
		}

		if ( ! $row ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql', true );

		unset( $this->cache[ $id ] );

		$lifecycle = isset( $row['status'] ) || array_key_exists( 'expires_at', $row );
		$before    = $lifecycle ? $this->find( $id ) : null;

		unset( $this->cache[ $id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$updated = false !== $wpdb->update( $this->table(), $row, array( 'id' => $id ) );

		if ( $updated && $before instanceof Agent ) {
			$after = $this->find( $id );

			if ( $after instanceof Agent ) {
				/**
				 * Fires after an agent's status or expiry is written.
				 *
				 * Listeners compare is_active() on the two to react to an
				 * agent being suspended, expiring or coming back.
				 *
				 * @param Agent $before The agent before the change.
				 * @param Agent $after  The agent after it.
				 */
				do_action( 'ai_agent_os_agent_status_changed', $before, $after );
			}
		}

		return $updated;
	}

	/**
	 * Merge new values into an agent's metadata.
	 *
	 * @param int                  $id      Agent id.
	 * @param array<string, mixed> $changes Meta keys to set.
	 */
	public function update_meta( int $id, array $changes ): bool {
		/*
		 * The merge base comes from the database, not from the cache. A cached
		 * agent describes the row as it was when it was read; merging onto it
		 * writes back every other change made since, which for an agent means
		 * silently reverting things like a destructive-mode toggle. The cache is
		 * dropped first so the read cannot be served from it.
		 */
		unset( $this->cache[ $id ] );

		$agent = $this->find( $id );

		if ( ! $agent instanceof Agent ) {
			return false;
		}

		return $this->update( $id, array( 'meta' => array_merge( $agent->get_meta(), $changes ) ) );
	}

	/**
	 * Record that a token was just accepted.
	 *
	 * Best-effort and deliberately unchecked: a failure to update the timestamp
	 * must never fail the request that succeeded in authenticating.
	 *
	 * @param int $id Agent id.
	 */
	public function touch_token_used( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, best-effort write.
		$wpdb->update(
			$this->table(),
			array( 'token_last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);

		unset( $this->cache[ $id ] );
	}

	/**
	 * Turn a row into an Agent, caching it.
	 *
	 * @param array<string, mixed>|null $row Database row.
	 */
	private function hydrate( ?array $row ): ?Agent {
		if ( ! is_array( $row ) || empty( $row['id'] ) ) {
			return null;
		}

		$agent = new Agent( $row );

		$this->cache[ $agent->get_id() ] = $agent;

		return $agent;
	}

	/**
	 * Clear the per-request cache. Used by tests.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}
}
