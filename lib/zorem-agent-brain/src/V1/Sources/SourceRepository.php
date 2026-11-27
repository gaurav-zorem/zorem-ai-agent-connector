<?php
/**
 * Configured knowledge sources.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Sources;

use ZoremAgentBrain\V1\Schema\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Rows of `zab_sources`: what a merchant configured, and how its last run went.
 *
 * One row per configured source — the Markdown uploads, each GitHub
 * repository. The row is the source's memory between runs (which files it
 * wrote, the ETag it last saw) and what a status screen reads: status, whether
 * the last run was complete, and the error if it was not.
 *
 * Secrets never sit in `config` in the clear: the GitHub source seals its token
 * with SecretBox before saving.
 */
final class SourceRepository {

	public const STATUS_IDLE     = 'idle';
	public const STATUS_OK       = 'ok';
	public const STATUS_ERROR    = 'error';
	public const STATUS_DISABLED = 'disabled';

	/**
	 * The table.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_SOURCES );
	}

	/**
	 * Create a source, or replace its label and config.
	 *
	 * @param string               $key    Unique key, e.g. "github:acme-docs".
	 * @param string               $type   Source type, e.g. "github".
	 * @param string               $label  Human-readable name.
	 * @param array<string, mixed> $config Configuration, stored as JSON.
	 * @return int|\WP_Error Row id.
	 */
	public function save( string $key, string $type, string $label, array $config ) {
		if ( '' === $key || strlen( $key ) > 191 || 1 !== preg_match( '/^[a-z0-9_:.-]+$/', $key ) ) {
			return new \WP_Error( 'zorem_agent_brain_source_invalid_key', __( 'A source needs a key of lowercase letters, digits and : . _ -', 'zorem-ai-agent-connector' ) );
		}

		global $wpdb;

		$now      = current_time( 'mysql', true );
		$existing = $this->find( $key );
		$row      = array(
			'type'       => $type,
			'label'      => $label,
			'config'     => wp_json_encode( $config ),
			'updated_at' => $now,
		);

		if ( null !== $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
			if ( false === $wpdb->update( $this->table(), $row, array( 'source_key' => $key ) ) ) {
				return new \WP_Error( 'zorem_agent_brain_source_save_failed', __( 'The source could not be saved.', 'zorem-ai-agent-connector' ) );
			}

			return (int) $existing['id'];
		}

		$row['source_key'] = $key;
		$row['status']     = self::STATUS_IDLE;
		$row['created_at'] = $now;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		if ( false === $wpdb->insert( $this->table(), $row ) ) {
			return new \WP_Error( 'zorem_agent_brain_source_save_failed', __( 'The source could not be saved.', 'zorem-ai-agent-connector' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * One source by key, with its config decoded.
	 *
	 * @param string $key Source key.
	 * @return array<string, mixed>|null
	 */
	public function find( string $key ): ?array {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; value bound.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE source_key = %s', $table, $key ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Every source, optionally of one type.
	 *
	 * @param string $type Source type, or '' for all.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( string $type = '' ): array {
		global $wpdb;

		$table = $this->table();

		if ( '' === $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; no input.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY source_key ASC', $table ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; value bound.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s ORDER BY source_key ASC', $table, $type ), ARRAY_A );
		}

		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * Record a run's outcome, merging config changes (an ETag, the files written).
	 *
	 * Config keys set to null are removed.
	 *
	 * @param string               $key     Source key.
	 * @param string               $status  One of the STATUS_* constants.
	 * @param bool                 $complete Whether the run covered everything.
	 * @param string               $error   Error text, '' for none.
	 * @param array<string, mixed> $config_changes Config keys to set.
	 */
	public function record_run( string $key, string $status, bool $complete, string $error = '', array $config_changes = array() ): bool {
		$existing = $this->find( $key );

		if ( null === $existing ) {
			return false;
		}

		$config = array_merge( $existing['config'], $config_changes );
		$config = array_filter( $config, static fn ( $value ): bool => null !== $value );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->update(
			$this->table(),
			array(
				'status'      => $status,
				'complete'    => $complete ? 1 : 0,
				'last_error'  => '' === $error ? null : mb_substr( $error, 0, 1000 ),
				'last_run_at' => current_time( 'mysql', true ),
				'config'      => wp_json_encode( $config ),
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'source_key' => $key )
		);
	}

	/**
	 * Delete a source's row. Its entries are the source's to remove first.
	 *
	 * @param string $key Source key.
	 */
	public function delete( string $key ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return false !== $wpdb->delete( $this->table(), array( 'source_key' => $key ) );
	}

	/**
	 * Normalise a row.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		$config = json_decode( (string) ( $row['config'] ?? '' ), true );

		return array(
			'id'          => (int) $row['id'],
			'key'         => (string) $row['source_key'],
			'type'        => (string) $row['type'],
			'label'       => (string) $row['label'],
			'config'      => is_array( $config ) ? $config : array(),
			'status'      => (string) $row['status'],
			'complete'    => (bool) (int) $row['complete'],
			'last_run_at' => null === $row['last_run_at'] ? null : (string) $row['last_run_at'],
			'last_error'  => (string) ( $row['last_error'] ?? '' ),
		);
	}
}
