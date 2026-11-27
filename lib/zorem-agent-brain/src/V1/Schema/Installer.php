<?php
/**
 * The shared Brain store's schema.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the `zab_*` tables: one store, shared by every Agent OS plugin installed.
 *
 * Whichever plugin activates first creates the tables. A plugin activating
 * later finds the schema version already recorded and only upgrades it if its
 * kit is newer — which is how a second plugin "detects" existing Brain data:
 * it is in the tables the second plugin now uses.
 *
 * Schema changes within a major only add tables, columns and indexes, so an
 * older plugin running against a newer schema loses nothing (plan §8.5).
 *
 * Every index fits MyISAM's 1000-byte key limit. Many hosts still default to
 * MyISAM, and the WordPress test suite's InnoDB temporary tables allow 3072
 * bytes, so an index that is too long passes every test and fails on a
 * customer's site. A test computes each index's size from schema() for that
 * reason.
 *
 * install() verifies that every table exists before recording the schema
 * version. A table MySQL refused is recorded as a failure — which tables, and
 * the database error — not hidden behind a version number that claims success.
 */
final class Installer {

	/** Schema version. Bump with every schema change. */
	public const DB_VERSION = '1.2.0';

	/** Option holding the installed schema version, once per site. */
	public const DB_VERSION_OPTION = 'zorem_agent_brain_db_version';

	/** Option recording the last failed install: missing tables and the database error. */
	public const FAILURE_OPTION = 'zorem_agent_brain_install_failure';

	/** Table base names. */
	public const TABLE_KNOWLEDGE = 'zab_knowledge';
	public const TABLE_SOURCES   = 'zab_sources';
	public const TABLE_SCOPE     = 'zab_scope';

	/**
	 * Collection tables, retired in schema 1.1.0 with the feature. Kept only
	 * so an upgrade and the uninstaller can drop them.
	 */
	public const RETIRED_TABLES = array( 'zab_collections', 'zab_entry_collections' );

	/**
	 * Full table name for a base name. Always $wpdb->prefix plus a constant.
	 *
	 * @param string $basename One of the TABLE_* constants.
	 */
	public static function table( string $basename ): string {
		global $wpdb;

		return $wpdb->prefix . $basename;
	}

	/**
	 * Every table the store owns, as base names.
	 *
	 * @return array<int, string>
	 */
	public static function all_tables(): array {
		return array(
			self::TABLE_KNOWLEDGE,
			self::TABLE_SOURCES,
			self::TABLE_SCOPE,
		);
	}

	/**
	 * CREATE TABLE statements for dbDelta, one per table in all_tables().
	 *
	 * @return array<int, string>
	 */
	public static function schema(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$knowledge       = self::table( self::TABLE_KNOWLEDGE );
		$sources         = self::table( self::TABLE_SOURCES );
		$scope           = self::table( self::TABLE_SCOPE );

		return array(
			"CREATE TABLE {$knowledge} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				entry_key varchar(191) NOT NULL,
				source_type varchar(24) NOT NULL DEFAULT 'plugin_extracted',
				source_ref varchar(191) DEFAULT NULL,
				plugin_slug varchar(100) DEFAULT NULL,
				plugin_version varchar(32) DEFAULT NULL,
				section varchar(32) NOT NULL DEFAULT 'overview',
				title varchar(191) NOT NULL,
				summary text DEFAULT NULL,
				content longtext DEFAULT NULL,
				facts longtext DEFAULT NULL,
				trust_status varchar(20) NOT NULL DEFAULT 'approved',
				priority smallint(5) NOT NULL DEFAULT 50,
				checksum char(32) DEFAULT NULL,
				token_estimate int(10) unsigned DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				meta longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY entry_key (entry_key),
				KEY plugin_section (plugin_slug,section),
				KEY source_trust (source_type,trust_status),
				KEY updated_at (updated_at)
			) {$charset_collate};",

			"CREATE TABLE {$sources} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_key varchar(191) NOT NULL,
				type varchar(24) NOT NULL,
				label varchar(191) NOT NULL DEFAULT '',
				config longtext DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'idle',
				complete tinyint(1) NOT NULL DEFAULT 0,
				last_run_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_key (source_key),
				KEY type_status (type,status)
			) {$charset_collate};",

			"CREATE TABLE {$scope} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				reader_ref varchar(100) NOT NULL,
				scope_type varchar(20) NOT NULL,
				scope_value varchar(191) NOT NULL,
				scope_hash char(32) NOT NULL,
				granted tinyint(1) NOT NULL DEFAULT 1,
				granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY reader_scope (reader_ref,scope_hash),
				KEY reader_granted (reader_ref,granted)
			) {$charset_collate};",
		);
	}

	/**
	 * Create or upgrade the schema and record the version.
	 *
	 * Never lowers the recorded version: an older kit running install() on a
	 * site whose schema a newer kit already upgraded leaves it alone.
	 */
	public static function install(): bool {
		global $wpdb;

		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( '' !== $installed && version_compare( $installed, self::DB_VERSION, '>' ) ) {
			return true;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::schema() );

		$missing = self::missing_tables();

		if ( array() !== $missing ) {
			update_option(
				self::FAILURE_OPTION,
				array(
					'missing'    => $missing,
					'db_error'   => (string) $wpdb->last_error,
					'db_version' => self::DB_VERSION,
					'failed_at'  => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);

			return false;
		}

		self::ensure_fulltext_index();

		// 1.0.2: uploaded and GitHub documents move out of `manual` into their own section.
		if ( '' !== $installed && version_compare( $installed, '1.0.2', '<' ) ) {
			$knowledge = self::table( self::TABLE_KNOWLEDGE );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; values bound.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET section = %s WHERE section = %s AND source_type IN (%s, %s)', $knowledge, 'document', 'manual', 'markdown', 'github' ) );
		}

		if ( '' !== $installed && version_compare( $installed, '1.1.0', '<' ) ) {
			self::retire_collections();
		}

		if ( '' !== $installed && version_compare( $installed, '1.2.0', '<' ) ) {
			self::retire_review();
		}

		delete_option( self::FAILURE_OPTION );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		return true;
	}

	/**
	 * Schema 1.1.0: collections, Markdown upload and GitHub documents are gone.
	 *
	 * Their entries go: an internal collection was what kept an uploaded
	 * document from shoppers, and without one any section rule would open it.
	 * So do the source rows that held GitHub tokens, every collection rule,
	 * and the two collection tables.
	 */
	private static function retire_collections(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time cleanup of the Brain's own tables; identifiers through %i, values bound.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE source_type IN (%s, %s)', self::table( self::TABLE_KNOWLEDGE ), 'markdown', 'github' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE type IN (%s, %s)', self::table( self::TABLE_SOURCES ), 'markdown', 'github' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE scope_type = %s', self::table( self::TABLE_SCOPE ), 'collection' ) );

		foreach ( self::RETIRED_TABLES as $basename ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $basename ) ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Schema 1.2.0: the Review tab and AI drafts are gone.
	 *
	 * A chosen page's change that waited for review is what the page says now,
	 * and page edits apply at once from here on, so it is approved. An AI draft
	 * nobody approved has no way out any more, so it is deleted; AI-written
	 * entries a person already approved stay.
	 */
	private static function retire_review(): void {
		global $wpdb;

		$knowledge = self::table( self::TABLE_KNOWLEDGE );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- One-time cleanup of the Brain's own table; identifier through %i, values bound.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET trust_status = %s WHERE trust_status = %s AND source_type = %s', $knowledge, 'approved', 'draft', 'wp_content' ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE trust_status = %s AND source_type = %s', $knowledge, 'draft', 'ai_generated' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Tables in all_tables() that do not exist.
	 *
	 * Probed with a query rather than SHOW TABLES, which does not list
	 * temporary tables (the WordPress test suite creates temporary ones).
	 *
	 * @return array<int, string> Base names of missing tables.
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$missing  = array();
		$suppress = $wpdb->suppress_errors( true );

		foreach ( self::all_tables() as $basename ) {
			$table = self::table( $basename );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; existence probe.
			if ( false === $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) ) ) {
				$missing[] = $basename;
			}
		}

		$wpdb->suppress_errors( $suppress );

		return $missing;
	}

	/**
	 * The last failed install, or null when the store is complete.
	 *
	 * @return array{missing: array<int, string>, db_error: string, db_version: string, failed_at: string}|null
	 */
	public static function failure(): ?array {
		$failure = get_option( self::FAILURE_OPTION, null );

		return is_array( $failure ) ? $failure : null;
	}

	/**
	 * Upgrade when the recorded schema is older than this kit's.
	 *
	 * @return bool Whether an upgrade ran.
	 */
	public static function maybe_upgrade(): bool {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );

		if ( '' !== $installed && version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return false;
		}

		self::install();

		return true;
	}

	/**
	 * Whether the store exists on this site.
	 */
	public static function is_installed(): bool {
		return '' !== (string) get_option( self::DB_VERSION_OPTION, '' );
	}

	/**
	 * Drop every table. Called only by Uninstaller, for the last host.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( array_map( array( self::class, 'table' ), array_merge( self::all_tables(), self::RETIRED_TABLES ) ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Table name is $wpdb->prefix plus a class constant; identifiers cannot be bound.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Whether the knowledge FULLTEXT index exists. Search uses LIKE without it.
	 */
	public static function has_fulltext_index(): bool {
		global $wpdb;

		$table    = self::table( self::TABLE_KNOWLEDGE );
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; schema probe.
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = 'knowledge_search'", $table ) );

		$wpdb->suppress_errors( $suppress );

		return is_array( $rows ) && array() !== $rows;
	}

	/**
	 * Add the FULLTEXT index when missing.
	 *
	 * Separate from dbDelta, which does not create FULLTEXT indexes reliably.
	 * Errors are suppressed: MySQL refuses FULLTEXT on temporary InnoDB tables
	 * (the WordPress test suite's) and some hosts restrict ALTER. Search falls
	 * back to LIKE, so a missing index costs ranking quality, not correctness.
	 */
	private static function ensure_fulltext_index(): void {
		if ( self::has_fulltext_index() ) {
			return;
		}

		global $wpdb;

		$table    = self::table( self::TABLE_KNOWLEDGE );
		$suppress = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; identifiers cannot be bound.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD FULLTEXT KEY knowledge_search (title,summary,content)', $table ) );

		$wpdb->suppress_errors( $suppress );
	}
}
