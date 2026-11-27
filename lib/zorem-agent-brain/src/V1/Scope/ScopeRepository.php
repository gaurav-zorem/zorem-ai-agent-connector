<?php
/**
 * Explicit knowledge scope rules, per reader.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Scope;

use ZoremAgentBrain\V1\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * The explicit allow and deny rules derivation cannot express.
 *
 * Ported from the legacy plugin, keyed by reader rather than agent id. A deny
 * beats every derived allow at its level and below — an administrator saying no is the end of the
 * conversation. Rules from either plugin share the table without colliding,
 * because reader refs are namespaced (`agent:…`, `exp:…`).
 */
final class ScopeRepository {

	public const SCOPE_ENTRY   = 'entry';
	public const SCOPE_SECTION = 'section';
	public const SCOPE_PLUGIN  = 'plugin';

	/**
	 * Per-request cache of rules, keyed by reader ref. Reads only.
	 *
	 * @var array<string, array<int, array{scope_type: string, scope_value: string, granted: bool, granted_by: int}>>
	 */
	private array $cache = array();

	/**
	 * Valid scope types, most specific first.
	 *
	 * @return array<int, string>
	 */
	public static function scope_types(): array {
		return array( self::SCOPE_ENTRY, self::SCOPE_SECTION, self::SCOPE_PLUGIN );
	}

	/**
	 * The scope table.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_SCOPE );
	}

	/**
	 * The unique hash of a rule, which keeps the index within MyISAM's limit.
	 *
	 * @param string $scope_type  Scope type.
	 * @param string $scope_value Scope value.
	 */
	public static function hash( string $scope_type, string $scope_value ): string {
		return md5( $scope_type . '|' . $scope_value );
	}

	/**
	 * Every rule for a reader.
	 *
	 * @param string $reader_ref Reader ref.
	 * @return array<int, array{scope_type: string, scope_value: string, granted: bool, granted_by: int}>
	 */
	public function for_reader( string $reader_ref ): array {
		if ( isset( $this->cache[ $reader_ref ] ) ) {
			return $this->cache[ $reader_ref ];
		}

		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; value is bound.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT scope_type, scope_value, granted, granted_by FROM %i WHERE reader_ref = %s', $table, $reader_ref ), ARRAY_A );

		$rules = array();

		foreach ( (array) $rows as $row ) {
			// A rule of a retired type (collection, until 1.3.0) means nothing now.
			if ( ! in_array( (string) $row['scope_type'], self::scope_types(), true ) ) {
				continue;
			}

			$rules[] = array(
				'scope_type'  => (string) $row['scope_type'],
				'scope_value' => (string) $row['scope_value'],
				'granted'     => (bool) $row['granted'],
				// Zero means a rule set by code (a profile sync), not a person; still shown as a rule.
				'granted_by'  => (int) $row['granted_by'],
			);
		}

		$this->cache[ $reader_ref ] = $rules;

		return $rules;
	}

	/**
	 * Allow or deny a scope for a reader. Replaces an existing rule for the same scope.
	 *
	 * @param string $reader_ref  Reader ref.
	 * @param string $scope_type  One of the SCOPE_* constants.
	 * @param string $scope_value Entry key, section name or plugin slug.
	 * @param bool   $granted     True to allow, false to deny.
	 * @param int    $granted_by  User id making the change; 0 for code.
	 */
	public function set( string $reader_ref, string $scope_type, string $scope_value, bool $granted, int $granted_by = 0 ): bool {
		if ( '' === $reader_ref || '' === $scope_value || ! in_array( $scope_type, self::scope_types(), true ) ) {
			return false;
		}

		/*
		 * Sections and plugins are lowercase slugs in the data, so their
		 * rules are stored lowercase too. MySQL compares them without
		 * case and the row check in PHP does not: a rule saved as `Store-Policy`
		 * was enforced by search and ignored by a fetch by key.
		 */
		if ( self::SCOPE_ENTRY !== $scope_type ) {
			$scope_value = strtolower( $scope_value );
		}

		global $wpdb;

		unset( $this->cache[ $reader_ref ] );

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Upsert on the Brain's own table; every value is bound.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (reader_ref, scope_type, scope_value, scope_hash, granted, granted_by, created_at)
				 VALUES (%s, %s, %s, %s, %d, %d, %s)
				 ON DUPLICATE KEY UPDATE granted = VALUES(granted), granted_by = VALUES(granted_by)',
				$table,
				$reader_ref,
				$scope_type,
				$scope_value,
				self::hash( $scope_type, $scope_value ),
				$granted ? 1 : 0,
				$granted_by,
				current_time( 'mysql', true )
			)
		);

		return false !== $result;
	}

	/**
	 * Remove one rule, returning that scope to derived access.
	 *
	 * @param string $reader_ref  Reader ref.
	 * @param string $scope_type  Scope type.
	 * @param string $scope_value Scope value.
	 */
	public function remove( string $reader_ref, string $scope_type, string $scope_value ): bool {
		global $wpdb;

		// The same lowercasing as set(), so the hash finds the rule set() stored.
		if ( self::SCOPE_ENTRY !== $scope_type ) {
			$scope_value = strtolower( $scope_value );
		}

		unset( $this->cache[ $reader_ref ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->delete(
			$this->table(),
			array(
				'reader_ref' => $reader_ref,
				'scope_hash' => self::hash( $scope_type, $scope_value ),
			),
			array( '%s', '%s' )
		);
	}

	/**
	 * Copy every rule from one reader to another, keeping existing target rules.
	 *
	 * How an Experiences reader's access becomes a Core agent's without anyone
	 * re-entering it (plan §9.4).
	 *
	 * @param string $from_ref Source reader ref.
	 * @param string $to_ref   Target reader ref.
	 * @return int Rules copied.
	 */
	public function copy_rules( string $from_ref, string $to_ref ): int {
		$existing = array();

		foreach ( $this->for_reader( $to_ref ) as $rule ) {
			$existing[ self::hash( $rule['scope_type'], $rule['scope_value'] ) ] = true;
		}

		$copied = 0;

		foreach ( $this->for_reader( $from_ref ) as $rule ) {
			if ( isset( $existing[ self::hash( $rule['scope_type'], $rule['scope_value'] ) ] ) ) {
				continue;
			}

			if ( $this->set( $to_ref, $rule['scope_type'], $rule['scope_value'], $rule['granted'], $rule['granted_by'] ) ) {
				++$copied;
			}
		}

		return $copied;
	}

	/**
	 * Remove every rule for a reader.
	 *
	 * @param string $reader_ref Reader ref.
	 */
	public function delete_for_reader( string $reader_ref ): bool {
		global $wpdb;

		unset( $this->cache[ $reader_ref ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->delete( $this->table(), array( 'reader_ref' => $reader_ref ), array( '%s' ) );
	}

	/**
	 * Clear the per-request cache.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}
}
