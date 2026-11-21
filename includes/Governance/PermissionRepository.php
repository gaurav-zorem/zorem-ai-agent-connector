<?php
/**
 * Agent permission persistence.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use AiAgentOs\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the agent's per-ability allow-list.
 */
final class PermissionRepository {

	public const LEVEL_NONE  = 'none';
	public const LEVEL_READ  = 'read';
	public const LEVEL_WRITE = 'write';

	/**
	 * Per-request cache of grant maps, keyed by agent id.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $cache = array();

	/**
	 * The permissions table name.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_PERMISSIONS );
	}

	/**
	 * Valid grant levels.
	 *
	 * @return array<int, string>
	 */
	public static function levels(): array {
		return array( self::LEVEL_NONE, self::LEVEL_READ, self::LEVEL_WRITE );
	}

	/**
	 * All grants for the agent, as ability name => grant level.
	 *
	 * Rows recorded as 'none' are returned as well. They are an explicit,
	 * audited "no" from an admin rather than an absence, and the matrix screen
	 * needs to show the difference.
	 *
	 * @param int $agent_id Agent id.
	 * @return array<string, string>
	 */
	public function get_grants( int $agent_id ): array {
		if ( isset( $this->cache[ $agent_id ] ) ) {
			return $this->cache[ $agent_id ];
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT ability_name, grant_level FROM %i WHERE agent_id = %d', $table, $agent_id ),
			ARRAY_A
		);

		$grants = array();

		foreach ( (array) $rows as $row ) {
			$grants[ (string) $row['ability_name'] ] = (string) $row['grant_level'];
		}

		$this->cache[ $agent_id ] = $grants;

		return $grants;
	}

	/**
	 * The grant level for one ability. 'none' when never granted.
	 *
	 * @param int    $agent_id     Agent id.
	 * @param string $ability_name Ability name.
	 */
	public function get_grant( int $agent_id, string $ability_name ): string {
		$grants = $this->get_grants( $agent_id );

		return $grants[ $ability_name ] ?? self::LEVEL_NONE;
	}

	/**
	 * Set one grant.
	 *
	 * @param int    $agent_id     Agent id.
	 * @param string $ability_name Ability name.
	 * @param string $level        One of the LEVEL_* constants.
	 * @param int    $granted_by   Admin user id making the change.
	 */
	public function set_grant( int $agent_id, string $ability_name, string $level, int $granted_by = 0 ): bool {
		if ( ! in_array( $level, self::levels(), true ) ) {
			return false;
		}

		global $wpdb;

		$now = current_time( 'mysql', true );

		unset( $this->cache[ $agent_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; all values are bound.
		$result = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
				"INSERT INTO {$this->table()} (agent_id, ability_name, grant_level, granted_by, created_at, updated_at)
				 VALUES (%d, %s, %s, %d, %s, %s)
				 ON DUPLICATE KEY UPDATE grant_level = VALUES(grant_level), granted_by = VALUES(granted_by), updated_at = VALUES(updated_at)",
				$agent_id,
				$ability_name,
				$level,
				$granted_by,
				$now,
				$now
			)
		);

		return false !== $result;
	}

	/**
	 * Apply a batch of grants.
	 *
	 * @param int                   $agent_id   Agent id.
	 * @param array<string, string> $grants     Ability name => level.
	 * @param int                   $granted_by Admin user id making the change.
	 * @return int Number of grants written.
	 */
	public function set_grants( int $agent_id, array $grants, int $granted_by = 0 ): int {
		$written = 0;

		foreach ( $grants as $ability_name => $level ) {
			if ( $this->set_grant( $agent_id, (string) $ability_name, (string) $level, $granted_by ) ) {
				++$written;
			}
		}

		return $written;
	}

	/**
	 * Set every existing grant for the agent to 'none'.
	 *
	 * Used by the "revoke all permissions" action. The rows are kept rather
	 * than deleted so the admin's decision stays visible in the matrix.
	 *
	 * @param int $agent_id   Agent id.
	 * @param int $granted_by Admin user id making the change.
	 */
	public function revoke_all( int $agent_id, int $granted_by = 0 ): int {
		global $wpdb;

		unset( $this->cache[ $agent_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; values are bound.
		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
				"UPDATE {$this->table()} SET grant_level = %s, granted_by = %d, updated_at = %s WHERE agent_id = %d",
				self::LEVEL_NONE,
				$granted_by,
				current_time( 'mysql', true ),
				$agent_id
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Remove every permission row for the agent. Used when deleting the agent.
	 *
	 * @param int $agent_id Agent id.
	 */
	public function delete_for_agent( int $agent_id ): bool {
		global $wpdb;

		unset( $this->cache[ $agent_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		return false !== $wpdb->delete( $this->table(), array( 'agent_id' => $agent_id ), array( '%d' ) );
	}

	/**
	 * How many abilities the agent can actually use, by level.
	 *
	 * @param int $agent_id Agent id.
	 * @return array{read: int, write: int, total: int}
	 */
	public function count_grants( int $agent_id ): array {
		$counts = array(
			'read'  => 0,
			'write' => 0,
			'total' => 0,
		);

		foreach ( $this->get_grants( $agent_id ) as $level ) {
			if ( self::LEVEL_READ === $level ) {
				++$counts['read'];
				++$counts['total'];
			} elseif ( self::LEVEL_WRITE === $level ) {
				++$counts['write'];
				++$counts['total'];
			}
		}

		return $counts;
	}

	/**
	 * Clear the per-request cache. Used by tests and after bulk writes.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}
}
