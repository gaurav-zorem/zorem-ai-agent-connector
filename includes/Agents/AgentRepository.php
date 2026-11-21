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
 * Storage for the site's agent.
 *
 * The agent is the Admin Assistant. Its settings live in a
 * row of the plugin's agents table, created when the plugin is set up and
 * removed with the plugin's data on uninstall. Every method here reads or
 * writes that agent; none takes an id.
 *
 * Every query is prepared. Table names are built from $wpdb->prefix plus a
 * class constant and never from input, so interpolating them is safe;
 * identifiers cannot be bound as placeholders in any case.
 */
final class AgentRepository {

	/**
	 * The agent, once read in this request.
	 *
	 * @var Agent|null
	 */
	private ?Agent $cache = null;

	/**
	 * The agents table name.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_AGENTS );
	}

	/**
	 * The site's agent, or null before it has been set up.
	 */
	public function get(): ?Agent {
		if ( $this->cache instanceof Agent ) {
			return $this->cache;
		}

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
	 * The agent, when a presented token hash is its token's.
	 *
	 * Looked up in the table rather than compared on the object: the hash is
	 * never loaded into an Agent, so it cannot reach to_array() or a log.
	 *
	 * @param string $hash HMAC of the presented token.
	 */
	public function get_by_token_hash( string $hash ): ?Agent {
		if ( '' === $hash ) {
			return null;
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s', $table, $hash ),
			ARRAY_A
		);

		return $this->hydrate( $row );
	}

	/**
	 * The agent, when a user id is its WordPress account.
	 *
	 * @param int $user_id User id.
	 */
	public function get_by_shadow_user( int $user_id ): ?Agent {
		$agent = $user_id < 1 ? null : $this->get();

		return $agent instanceof Agent && $agent->get_shadow_user_id() === $user_id ? $agent : null;
	}

	/**
	 * The agent, when a bridge reference (`agent:<uuid>`) names it.
	 *
	 * @param string $ref Reference.
	 */
	public function get_by_ref( string $ref ): ?Agent {
		$agent = $this->get();

		return $agent instanceof Agent && hash_equals( $agent->ref(), $ref ) ? $agent : null;
	}

	/**
	 * Store the agent. Called once, by SiteAgent, when the plugin is set up.
	 *
	 * @param array<string, mixed> $data Column values.
	 * @return Agent|\WP_Error The stored agent.
	 */
	public function create( array $data ) {
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

		$this->cache = null;
		$agent       = false === $inserted ? null : $this->get();

		if ( ! $agent instanceof Agent ) {
			return new \WP_Error(
				'ai_agent_os_agent_insert_failed',
				__( 'The agent could not be saved.', 'zorem-ai-agent-connector' )
			);
		}

		return $agent;
	}

	/**
	 * Change the agent's settings.
	 *
	 * @param array<string, mixed> $data Column values to change.
	 */
	public function update( array $data ): bool {
		global $wpdb;

		$this->cache = null;
		$before      = $this->get();

		if ( ! $before instanceof Agent ) {
			return false;
		}

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

			// Who the agent acts for decides what it may read; an unknown value is never stored.
			if ( 'acts_for' === $key && ! in_array( $value, Agent::acts_for_values(), true ) ) {
				continue;
			}

			$row[ $key ] = $value;
		}

		if ( ! $row ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql', true );

		$lifecycle = isset( $row['status'] ) || array_key_exists( 'expires_at', $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$updated = false !== $wpdb->update( $this->table(), $row, array( 'id' => $before->get_id() ) );

		$this->cache = null;

		if ( $updated && $lifecycle ) {
			$after = $this->get();

			if ( $after instanceof Agent ) {
				/**
				 * Fires after the agent's status or expiry is written.
				 *
				 * Listeners compare is_active() on the two to react to the
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
	 * Merge new values into the agent's metadata.
	 *
	 * @param array<string, mixed> $changes Meta keys to set.
	 */
	public function update_meta( array $changes ): bool {
		/*
		 * The merge base comes from the database, not from the cache. A cached
		 * agent describes the row as it was when it was read; merging onto it
		 * writes back every other change made since, which would silently
		 * revert things like a destructive-mode toggle.
		 */
		$this->cache = null;

		$agent = $this->get();

		if ( ! $agent instanceof Agent ) {
			return false;
		}

		return $this->update( array( 'meta' => array_merge( $agent->get_meta(), $changes ) ) );
	}

	/**
	 * Record that the agent's token was just accepted.
	 *
	 * Best-effort and deliberately unchecked: a failure to update the timestamp
	 * must never fail the request that succeeded in authenticating.
	 */
	public function touch_token_used(): void {
		global $wpdb;

		$agent = $this->get();

		if ( ! $agent instanceof Agent ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table, best-effort write.
		$wpdb->update(
			$this->table(),
			array( 'token_last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $agent->get_id() )
		);

		$this->cache = null;
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

		$this->cache = new Agent( $row );

		return $this->cache;
	}

	/**
	 * Clear the per-request cache.
	 */
	public function flush_cache(): void {
		$this->cache = null;
	}
}
