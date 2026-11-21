<?php
/**
 * Database schema.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, upgrades and drops this plugin's own tables (`aaos_*`).
 *
 * Declared in `schema()`, listed in `all_tables()`, dropped by `drop_tables()`
 * — which derives from `all_tables()` rather than keeping a list of its own.
 * Tables belonging to any other plugin are that plugin's to create and drop,
 * never this one's.
 *
 * Every table is verified after `dbDelta()`, and the schema version is only
 * recorded when all of them exist; a refused table is recorded by name in
 * `FAILURE_OPTION` and shown on the status screen. Every index fits MyISAM's
 * 1000-byte key limit (`SchemaSafetyTest`).
 */
final class Installer {

	/** Schema version. Bump with every schema change. */
	public const DB_VERSION = '0.12.0';

	/** Option holding the installed schema version. */
	public const DB_VERSION_OPTION = 'ai_agent_os_db_version';

	/** Option holding the last install failure. */
	public const FAILURE_OPTION = 'ai_agent_os_install_failure';

	/** The role of the agent's shadow user: `read` only; grants come from the gate, not the role. */
	public const AGENT_ROLE = 'aaos_agent';

	/** The agent: identity, status, expiry, token hash, shadow user. */
	public const TABLE_AGENTS = 'aaos_agents';

	/** Grants: one row per ability. */
	public const TABLE_PERMISSIONS = 'aaos_agent_permissions';

	/**
	 * Retired in 1.0.1 (schema 0.12.0): the activity log. This plugin keeps no
	 * record of the agent's calls, so the table is no longer created. Kept as a
	 * name only, so a site that already has it is cleaned up on uninstall.
	 */
	public const TABLE_AUDIT = 'aaos_audit';

	/**
	 * Retired in 1.0.1 (schema 0.9.0): Business Skills, one row per manual.
	 * Kept as a name only, so retire_skills() can drop it from sites that
	 * still have it. See SKILLS-FEATURE-REMOVED.md in the project folder.
	 */
	public const TABLE_SKILLS = 'aaos_skills';

	/** Retired in 1.0.1: the daily job that drafted skill manuals. */
	private const RETIRED_SKILLS_CRON = 'ai_agent_os_generate_skills';

	/**
	 * Retired in 1.0.1 (schema 0.8.0): the links to WooCommerce API keys issued
	 * to the agent, for the classic REST door that was removed. Kept as a name
	 * only, so retire_wc_keys() can drop it from sites that still have it.
	 */
	public const TABLE_WC_KEYS = 'aaos_agent_wc_keys';

	/**
	 * Retired in 1.0.1 (schema 0.12.0): the daily roll-up of the activity log.
	 * With no log there is nothing to roll up. Kept as a name only, so a site
	 * that already has it is cleaned up on uninstall.
	 */
	public const TABLE_USAGE = 'aaos_mcp_usage_daily';

	/**
	 * Retired in 1.0.1 (schema 0.10.0): clients that registered themselves
	 * (Dynamic Client Registration). Kept as a name only, so
	 * retire_client_registration() can drop it. See
	 * CLIENT-REGISTRATION-REMOVED.md in the project folder.
	 */
	public const TABLE_OAUTH_CLIENTS = 'aaos_oauth_clients';

	/** Connect flow: authorization codes, access and refresh tokens, stored as hashes. */
	public const TABLE_OAUTH_TOKENS = 'aaos_oauth_tokens';

	/**
	 * Full table name for a base name.
	 *
	 * @param string $basename One of the TABLE_* constants.
	 */
	public static function table( string $basename ): string {
		global $wpdb;

		return $wpdb->prefix . $basename;
	}

	/**
	 * Every table this plugin owns, as base names.
	 *
	 * @return array<int, string>
	 */
	public static function all_tables(): array {
		return array( self::TABLE_AGENTS, self::TABLE_PERMISSIONS, self::TABLE_OAUTH_TOKENS );
	}

	/**
	 * CREATE TABLE statements for dbDelta, one per table in all_tables().
	 *
	 * @return array<int, string>
	 */
	public static function schema(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$agents          = self::table( self::TABLE_AGENTS );
		$permissions     = self::table( self::TABLE_PERMISSIONS );
		$oauth_tokens    = self::table( self::TABLE_OAUTH_TOKENS );

		return array(
			"CREATE TABLE {$agents} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				name varchar(191) NOT NULL,
				slug varchar(191) NOT NULL,
				acts_for varchar(10) NOT NULL DEFAULT 'admin',
				status varchar(20) NOT NULL DEFAULT 'active',
				auth_method varchar(20) NOT NULL DEFAULT 'bearer',
				token_hash char(64) DEFAULT NULL,
				token_prefix varchar(16) DEFAULT NULL,
				token_created_at datetime DEFAULT NULL,
				token_last_used_at datetime DEFAULT NULL,
				shadow_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				expires_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				meta longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY slug (slug),
				UNIQUE KEY token_hash (token_hash),
				KEY status_expires (status,expires_at),
				KEY shadow_user_id (shadow_user_id)
			) {$charset_collate};",

			"CREATE TABLE {$permissions} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agent_id bigint(20) unsigned NOT NULL,
				ability_name varchar(191) NOT NULL,
				grant_level varchar(10) NOT NULL DEFAULT 'none',
				source varchar(16) NOT NULL DEFAULT 'admin',
				granted_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY agent_ability (agent_id,ability_name),
				KEY agent_grant (agent_id,grant_level)
			) {$charset_collate};",

			"CREATE TABLE {$oauth_tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				token_hash char(64) NOT NULL,
				token_type varchar(10) NOT NULL,
				agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				grant_id char(36) NOT NULL,
				client_id varchar(255) NOT NULL DEFAULT '',
				redirect_uri text DEFAULT NULL,
				code_challenge varchar(128) DEFAULT NULL,
				resource varchar(255) DEFAULT NULL,
				grant_created_at datetime DEFAULT NULL,
				expires_at datetime NOT NULL,
				consumed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY agent_type (agent_id,token_type),
				KEY grant_id (grant_id),
				KEY expires_at (expires_at)
			) {$charset_collate};",
		);
	}

	/**
	 * Create or upgrade the schema; record the version only when every table exists.
	 */
	public static function install(): bool {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$suppressed = $wpdb->suppress_errors( true );
		dbDelta( self::schema() );
		$wpdb->suppress_errors( $suppressed );

		$missing = self::missing_tables();

		if ( array() !== $missing ) {
			update_option(
				self::FAILURE_OPTION,
				array(
					'missing'  => $missing,
					'db_error' => (string) $wpdb->last_error,
					'at'       => current_time( 'mysql', true ),
				),
				false
			);

			return false;
		}

		self::register_role();
		self::retire_wc_keys();
		self::retire_skills();
		self::retire_client_registration();
		self::retire_activity_log();

		// Retired in 1.0.1 (schema 0.11.0): tool risk levels set by hand. Every tool is rated automatically.
		delete_option( 'ai_agent_os_risk_overrides' );

		delete_option( self::FAILURE_OPTION );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );

		return true;
	}

	/**
	 * Remove what the retired WooCommerce REST door left behind.
	 *
	 * Every WooCommerce API key owned by the agent's account is deleted, so a
	 * key issued before the door was removed (or leaked since) stops working
	 * at once rather than when someone remembers it; then the link table goes.
	 * Keys belonging to people are untouched. Safe to run more than once.
	 */
	public static function retire_wc_keys(): void {
		global $wpdb;

		self::delete_agent_wc_keys();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This plugin's own retired table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( self::TABLE_WC_KEYS ) ) );
	}

	/**
	 * Remove the activity log's tables, but only while they are empty.
	 *
	 * This plugin keeps no record of the agent's calls. Earlier releases created
	 * these two tables on activation and never wrote a row to either, so a site
	 * upgrading from one is carrying empty tables for something that is not
	 * here. Those are dropped rather than left behind: a table that exists is a
	 * promise that something fills it.
	 *
	 * A table with rows is not ours to drop. AI Agent OS PRO shares these tables
	 * and writes its Activity Log into them, and while PRO runs it records an
	 * older schema version. When PRO is switched off and this plugin takes over
	 * again, it sees that older version and runs this method, which used to
	 * delete the site's whole activity history. Now the history stays, ready for
	 * PRO when it comes back; uninstalling with "Delete all data" still removes
	 * it.
	 *
	 * Safe to run more than once.
	 */
	public static function retire_activity_log(): void {
		global $wpdb;

		$dropped_audit = false;

		foreach ( array( self::TABLE_AUDIT, self::TABLE_USAGE ) as $basename ) {
			if ( self::table_has_rows( self::table( $basename ) ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This plugin's own retired table, and empty.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $basename ) ) );

			$dropped_audit = $dropped_audit || self::TABLE_AUDIT === $basename;
		}

		// The counter of rows the log could not write; it goes with the log's table.
		if ( $dropped_audit ) {
			delete_option( 'ai_agent_os_audit_failures' );
		}
	}

	/**
	 * Whether a table exists and holds at least one row.
	 *
	 * A missing table answers false, so it is "dropped" as before (a no-op).
	 *
	 * @param string $table Full table name.
	 */
	private static function table_has_rows( string $table ): bool {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence probe on this plugin's own table during install.
		$row = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );

		$wpdb->suppress_errors( $suppressed );

		return null !== $row;
	}

	/**
	 * Remove what the retired Skills feature left behind: its table and its daily job.
	 *
	 * Safe to run more than once.
	 */
	public static function retire_skills(): void {
		global $wpdb;

		wp_unschedule_hook( self::RETIRED_SKILLS_CRON );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This plugin's own retired table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( self::TABLE_SKILLS ) ) );
	}

	/**
	 * Remove what the retired client self-registration left behind.
	 *
	 * The clients that registered themselves (`zcl_…`) can no longer be
	 * recognised, so their tokens are deleted rather than left to fail one by
	 * one; then the table and the hourly registration counters go. Safe to
	 * run more than once.
	 */
	public static function retire_client_registration(): void {
		global $wpdb;

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This plugin's own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE client_id LIKE %s', self::table( self::TABLE_OAUTH_TOKENS ), $wpdb->esc_like( 'zcl_' ) . '%' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- This plugin's own retired table.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( self::TABLE_OAUTH_CLIENTS ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API deletes options by prefix.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'ai_agent_os_dcr_' ) . '%' ) );

		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Delete the WooCommerce API keys owned by the agent's account.
	 *
	 * Found by owner (an account with the agent role, or one the agent row
	 * names), not through the retired link table, so a key made by hand for an
	 * agent's account goes too. Nothing happens without WooCommerce's table.
	 */
	public static function delete_agent_wc_keys(): void {
		global $wpdb;

		$keys = $wpdb->prefix . 'woocommerce_api_keys';

		$suppressed = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existence probe on WooCommerce's key table.
		$exists = false !== $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $keys ) );

		if ( $exists ) {
			// The agent's account are kept out of user queries unless asked for.
			add_filter( 'ai_agent_os_include_shadow_users', '__return_true' );

			try {
				$owners = array_map(
					'intval',
					get_users(
						array(
							'role'   => self::AGENT_ROLE,
							'fields' => 'ID',
						)
					)
				);
			} finally {
				remove_filter( 'ai_agent_os_include_shadow_users', '__return_true' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- This plugin's own table.
			$named  = $wpdb->get_col( $wpdb->prepare( 'SELECT shadow_user_id FROM %i WHERE shadow_user_id > 0', self::table( self::TABLE_AGENTS ) ) );
			$owners = array_values( array_unique( array_filter( array_merge( $owners, array_map( 'intval', (array) $named ) ) ) ) );

			foreach ( $owners as $owner ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WooCommerce's key table, by owner.
				$wpdb->delete( $keys, array( 'user_id' => $owner ), array( '%d' ) );
			}
		}

		$wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Register the shadow users' role, with nothing but `read`.
	 */
	public static function register_role(): void {
		if ( null === get_role( self::AGENT_ROLE ) ) {
			add_role( self::AGENT_ROLE, __( 'AI agent (system)', 'zorem-ai-agent-connector' ), array( 'read' => true ) );
		}
	}

	/**
	 * Run the installer when the stored version is behind the code.
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::install();
		}

		// Once: existing agent accounts move to an address that can never receive mail.
		\AiAgentOs\Agents\ShadowUserManager::retire_routable_emails();
	}

	/**
	 * Tables that do not answer a query. Works on the temporary tables the test suite creates.
	 *
	 * @return array<int, string>
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$missing    = array();
		$suppressed = $wpdb->suppress_errors( true );

		foreach ( self::all_tables() as $basename ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Existence probe on this plugin's own table.
			if ( false === $wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', self::table( $basename ) ) ) ) {
				$missing[] = self::table( $basename );
			}
		}

		$wpdb->suppress_errors( $suppressed );

		return $missing;
	}

	/**
	 * The recorded install failure, or null.
	 *
	 * @return array{missing: array<int, string>, db_error: string, at: string}|null
	 */
	public static function failure(): ?array {
		$failure = get_option( self::FAILURE_OPTION, null );

		return is_array( $failure ) ? $failure : null;
	}

	/**
	 * Delete the agent's shadow users and their role. Only ever called from uninstall.php.
	 *
	 * Shadow users exist only to give agent requests a WordPress identity; they
	 * are never real people, and leaving them would leave orphaned accounts. The
	 * agents table is how they are found, so this runs before drop_tables(), and
	 * a user is deleted only while it still holds the agent role.
	 *
	 * @return int Users deleted.
	 */
	public static function remove_agent_users(): int {
		global $wpdb;

		$table = self::table( self::TABLE_AGENTS );

		// Any WooCommerce API key the agent's account owns goes with the account.
		self::delete_agent_wc_keys();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.WP.PreparedSQL -- Table name is $wpdb->prefix plus a class constant; uninstall context.
		$ids     = (array) $wpdb->get_col( $wpdb->prepare( 'SELECT shadow_user_id FROM %i WHERE shadow_user_id > 0', $table ) );
		$deleted = 0;

		$heir = null;

		if ( array() !== $ids ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';

			// What the agent created stays on the site, with the first administrator.
			$heir = \AiAgentOs\Agents\ShadowUserManager::heir();
		}

		/*
		 * Identified by the meta that marks an account as the agent's, not by
		 * its role: a role that an administrator or a role-editing plugin has
		 * changed would make the account unrecognisable here and leave it on
		 * the site after the plugin was gone.
		 */
		foreach ( $ids as $id ) {
			if ( \AiAgentOs\Agents\ShadowUserManager::is_agent_user( (int) $id ) && wp_delete_user( (int) $id, $heir ) ) {
				++$deleted;
			}
		}

		remove_role( self::AGENT_ROLE );

		return $deleted;
	}

	/**
	 * Drop every table. Only ever called from uninstall.php.
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( array_map( array( self::class, 'table' ), array_merge( self::all_tables(), array( self::TABLE_AUDIT, self::TABLE_USAGE, self::TABLE_WC_KEYS, self::TABLE_SKILLS, self::TABLE_OAUTH_CLIENTS ) ) ) as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.WP.PreparedSQL -- Table name is $wpdb->prefix plus a class constant; identifiers cannot be bound.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
