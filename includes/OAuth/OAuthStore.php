<?php
/**
 * Persistence for the connect flow.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\TokenService;
use AiAgentOs\Core\Cron;
use AiAgentOs\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Codes, tokens and registered clients.
 *
 * Every secret is stored as the same keyed HMAC the agent tokens use, so a
 * copy of the table is a list of hashes and nothing more. Lookups go through
 * the unique hash index; there is no column holding a usable credential.
 */
final class OAuthStore {

	public const TYPE_CODE    = 'code';
	public const TYPE_ACCESS  = 'access';
	public const TYPE_REFRESH = 'refresh';

	/**
	 * Lifetimes, in seconds. Codes are spent within seconds of being issued;
	 * access tokens are short-lived (S11) and renewed with a rotating refresh
	 * token (S10).
	 */
	public const CODE_TTL    = 120;
	public const ACCESS_TTL  = HOUR_IN_SECONDS;
	public const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Longest an approval lasts, however often it is refreshed.
	 *
	 * Each refresh issues a new 30-day refresh token, so without a ceiling a
	 * client that stays in use is connected for ever on one approval.
	 */
	public const MAX_GRANT_AGE = 90 * DAY_IN_SECONDS;

	/**
	 * Spent time given to a refresh token whose one replay has been used.
	 */
	public const GRACE_SPENT = '1970-01-01 00:00:01';

	/**
	 * Recognisable prefixes, so a leaked credential says what it is in a log.
	 */
	private const PREFIXES = array(
		self::TYPE_CODE    => 'zoc_',
		self::TYPE_ACCESS  => 'zoa_',
		self::TYPE_REFRESH => 'zor_',
	);

	/**
	 * Hashing shared with agent tokens.
	 *
	 * @var TokenService
	 */
	private TokenService $tokens;

	/**
	 * Constructor.
	 *
	 * @param TokenService $tokens Hashing shared with agent tokens.
	 */
	public function __construct( TokenService $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Hook revocation and cleanup.
	 */
	public function register(): void {
		/*
		 * D5: revoking the agent kills its refresh tokens. The status check at
		 * refresh time would refuse them anyway, but deleting them means that
		 * reactivating the agent later does not quietly bring a remote client
		 * back to life — it has to be approved again.
		 */
		add_action(
			'ai_agent_os_agent_revoked',
			function ( Agent $agent ): void {
				$this->delete_for_agent( $agent->get_id() );
			}
		);

		/*
		 * And any other way it stops being able to act: suspended, set to
		 * expire, or brought back after being inactive. Reactivating must not
		 * quietly bring a remote client back either.
		 */
		add_action(
			'ai_agent_os_agent_status_changed',
			function ( Agent $before, Agent $after ): void {
				if ( ! $before->is_active() || ! $after->is_active() ) {
					$this->delete_for_agent( $after->get_id() );
				}
			},
			10,
			2
		);

		// And giving it a new address: the old address stops working, so do the tokens issued for it.
		add_action(
			'ai_agent_os_mcp_address_replaced',
			function ( Agent $agent ): void {
				$this->delete_for_agent( $agent->get_id() );
			}
		);

		// And deleting one: its tokens could no longer work, and should not linger.
		add_action(
			'ai_agent_os_agent_deleted',
			function ( Agent $agent ): void {
				$this->delete_for_agent( $agent->get_id() );
			}
		);

		add_action( Cron::PRUNE_EVENT, array( $this, 'prune' ) );
	}

	/**
	 * Whether a string is shaped like one of our access tokens.
	 *
	 * @param string $token Candidate.
	 */
	public function looks_like_access_token( string $token ): bool {
		return 0 === strpos( $token, self::PREFIXES[ self::TYPE_ACCESS ] ) && strlen( $token ) > 20;
	}

	/**
	 * Issue a credential and store its hash.
	 *
	 * @param string               $type     One of the TYPE_* constants.
	 * @param int                  $agent_id Agent it acts as.
	 * @param string               $grant_id Approval it descends from.
	 * @param string               $client_id Client it was issued to.
	 * @param array<string, mixed> $extra    redirect_uri, code_challenge, resource, grant_created_at (now when absent).
	 * @return string|\WP_Error The plaintext credential, returned once.
	 */
	public function issue( string $type, int $agent_id, string $grant_id, string $client_id, array $extra = array() ) {
		global $wpdb;

		$ttl = array(
			self::TYPE_CODE    => self::CODE_TTL,
			self::TYPE_ACCESS  => self::ACCESS_TTL,
			self::TYPE_REFRESH => self::REFRESH_TTL,
		);

		if ( ! isset( $ttl[ $type ] ) ) {
			return new \WP_Error( 'ai_agent_os_oauth_bad_type', 'Unknown credential type.' );
		}

		try {
			$secret = self::PREFIXES[ $type ] . self::random_string();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_agent_os_oauth_no_csprng', $e->getMessage() );
		}

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$inserted = $wpdb->insert(
			$this->table(),
			array(
				'token_hash'       => $this->tokens->hash( $secret ),
				'token_type'       => $type,
				'agent_id'         => $agent_id,
				'grant_id'         => $grant_id,
				'client_id'        => $client_id,
				'redirect_uri'     => isset( $extra['redirect_uri'] ) ? (string) $extra['redirect_uri'] : null,
				'code_challenge'   => isset( $extra['code_challenge'] ) ? (string) $extra['code_challenge'] : null,
				'resource'         => isset( $extra['resource'] ) ? (string) $extra['resource'] : null,
				'grant_created_at' => ! empty( $extra['grant_created_at'] ) ? (string) $extra['grant_created_at'] : gmdate( 'Y-m-d H:i:s', $now ),
				'expires_at'       => gmdate( 'Y-m-d H:i:s', $now + $ttl[ $type ] ),
				'consumed_at'      => null,
				'created_at'       => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);

		if ( false === $inserted ) {
			return new \WP_Error( 'ai_agent_os_oauth_store_failed', __( 'The credential could not be saved.', 'zorem-ai-agent-connector' ) );
		}

		return $secret;
	}

	/**
	 * Find a credential row by its plaintext value.
	 *
	 * Returns the row whether or not it is expired or spent: the caller needs
	 * to tell "never existed" from "replayed", because only the second burns
	 * the grant.
	 *
	 * @param string $secret Plaintext credential.
	 * @param string $type   Expected type.
	 * @return array<string, mixed>|null
	 */
	public function find( string $secret, string $type ): ?array {
		global $wpdb;

		if ( '' === $secret || ! isset( self::PREFIXES[ $type ] ) || 0 !== strpos( $secret, self::PREFIXES[ $type ] ) ) {
			return null;
		}

		$hash  = $this->tokens->hash( $secret );
		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; values are bound.
		$row = $wpdb->get_row(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s AND token_type = %s', $table, $hash, $type ),
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Mark a credential spent, atomically.
	 *
	 * The UPDATE only matches an unspent row, so two concurrent exchanges of
	 * one code cannot both succeed: exactly one of them changes a row.
	 *
	 * @param int $id Row id.
	 */
	public function consume( int $id ): bool {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; values are bound.
		$changed = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL', $table, current_time( 'mysql', true ), $id )
		);

		return 1 === $changed;
	}

	/**
	 * Use up a spent refresh token's one replay inside the reuse grace, atomically.
	 *
	 * Moves its spent time to the epoch, so it is outside the grace from now
	 * on. Only the request that still sees the original spent time wins.
	 *
	 * @param int    $id          Row id.
	 * @param string $consumed_at The spent time the caller read.
	 */
	public function spend_grace( int $id, string $consumed_at ): bool {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; values are bound.
		$changed = $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- See above.
			$wpdb->prepare( 'UPDATE %i SET consumed_at = %s WHERE id = %d AND consumed_at = %s', $table, self::GRACE_SPENT, $id, $consumed_at )
		);

		return 1 === $changed;
	}

	/**
	 * Delete every credential descended from one approval.
	 *
	 * @param string $grant_id Grant id.
	 */
	public function delete_grant( string $grant_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->delete( $this->table(), array( 'grant_id' => $grant_id ), array( '%s' ) );
	}

	/**
	 * Delete every credential issued to the agent.
	 *
	 * @param int $agent_id Agent id.
	 */
	public function delete_for_agent( int $agent_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
		$wpdb->delete( $this->table(), array( 'agent_id' => $agent_id ), array( '%d' ) );
	}

	/**
	 * Every app signed in right now, per agent: one entry per approval (grant).
	 *
	 * For the agent's Connect page ("Connected apps", each with Disconnect)
	 * and the header. One query.
	 *
	 * @return array<int, array<string, array{client_id: string, last_at: string}>> Agent id => grant id => app.
	 */
	public function signed_in_apps(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read once per screen.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT agent_id, grant_id, client_id, MAX(created_at) AS last_at FROM %i WHERE token_type IN ( %s, %s ) AND consumed_at IS NULL AND expires_at > %s GROUP BY agent_id, grant_id, client_id ORDER BY last_at DESC',
				$this->table(),
				self::TYPE_ACCESS,
				self::TYPE_REFRESH,
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		$apps = array();

		foreach ( (array) $rows as $row ) {
			$apps[ (int) $row['agent_id'] ][ (string) $row['grant_id'] ] = array(
				'client_id' => (string) $row['client_id'],
				'last_at'   => (string) $row['last_at'],
			);
		}

		return $apps;
	}

	/**
	 * Whether a grant belongs to an agent (so a Disconnect cannot reach another agent's app).
	 *
	 * @param int    $agent_id Agent id.
	 * @param string $grant_id Grant id.
	 */
	public function grant_belongs_to( int $agent_id, string $grant_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; one check per Disconnect.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE agent_id = %d AND grant_id = %s LIMIT 1', $this->table(), $agent_id, $grant_id ) );
	}

	/**
	 * Remove expired credentials. Runs with the daily activity prune.
	 */
	public function prune(): void {
		global $wpdb;

		$table = $this->table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Identifier is internal; value is bound.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at < %s', $table, current_time( 'mysql', true ) ) );

		// Registered clients that never signed in, or whose credentials have all expired, after a week.
		$clients = $this->clients();

		if ( array() !== $clients ) {
			update_option( self::CLIENTS_OPTION, $this->cap_clients( $clients ), false );
		}

		// Registration counters from past hours (OAuthController::within_register_limit()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API deletes options by prefix.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s', $wpdb->options, $wpdb->esc_like( 'ai_agent_os_dcr_' ) . '%', '%' . $wpdb->esc_like( '_' . gmdate( 'YmdH' ) ) ) );
	}

	/**
	 * Remember the pair a refresh issued, for the client's own concurrent retry.
	 *
	 * Encrypted with a key made from the refresh token that was spent, so the
	 * stored copy opens only for someone who already holds that token, and
	 * kept only for the reuse grace.
	 *
	 * @param string               $refresh_token The refresh token that was spent.
	 * @param array<string, mixed> $pair          The response body issued for it.
	 * @param int                  $ttl           Seconds to keep it.
	 */
	public function remember_pair( string $refresh_token, array $pair, int $ttl ): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return;
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( \Exception $e ) {
			return;
		}

		$tag    = '';
		$cipher = openssl_encrypt( (string) wp_json_encode( $pair ), 'aes-256-gcm', self::pair_key( $refresh_token ), OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $cipher ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored as text.
		set_transient( self::pair_transient( $refresh_token ), base64_encode( $iv . $tag . $cipher ), $ttl );
	}

	/**
	 * The pair a refresh issued for this token within the grace, or null.
	 *
	 * @param string $refresh_token The refresh token presented again.
	 * @return array<string, mixed>|null
	 */
	public function remembered_pair( string $refresh_token ): ?array {
		$stored = get_transient( self::pair_transient( $refresh_token ) );

		if ( ! is_string( $stored ) || ! function_exists( 'openssl_decrypt' ) ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See remember_pair().
		$raw = base64_decode( $stored, true );

		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}

		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::pair_key( $refresh_token ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		$pair  = false === $plain ? null : json_decode( $plain, true );

		return is_array( $pair ) ? $pair : null;
	}

	/**
	 * Transient name for a remembered pair: a hash, never the token.
	 *
	 * @param string $refresh_token Refresh token.
	 */
	private static function pair_transient( string $refresh_token ): string {
		return 'ai_agent_os_rt_' . substr( hash_hmac( 'sha256', 'name|' . $refresh_token, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * Encryption key for a remembered pair.
	 *
	 * @param string $refresh_token Refresh token.
	 */
	private static function pair_key( string $refresh_token ): string {
		return hash_hmac( 'sha256', 'key|' . $refresh_token, wp_salt( 'auth' ), true );
	}

	/**
	 * Option holding the clients that registered themselves (Grok), client id => record.
	 *
	 * An option rather than a table: registration is open only to callback
	 * addresses on an allow-list (RegistrationPolicy), the list is capped at
	 * CLIENTS_KEPT, and the free plugin shares this plugin's tables, so a
	 * schema change for a short list was not worth it. Not autoloaded.
	 */
	public const CLIENTS_OPTION = 'ai_agent_os_oauth_clients';

	/** Most registered clients kept; the oldest unused go first. */
	public const CLIENTS_KEPT = 50;

	/** Prefix of a registered client's id, so it never looks like a metadata document URL. */
	public const CLIENT_PREFIX = 'zcl_';

	/**
	 * Store a client posted to the registration endpoint.
	 *
	 * @param array{client_name: string, redirect_uris: array<int, string>, auth_method: string} $client The client.
	 * @return array{client_id: string, client_secret: string|null}|\WP_Error
	 */
	public function register_client( array $client ) {
		try {
			$client_id = self::CLIENT_PREFIX . self::random_string( 18 );
			$secret    = 'none' === $client['auth_method'] ? null : 'zcs_' . self::random_string();
		} catch ( \Exception $e ) {
			return new \WP_Error( 'ai_agent_os_oauth_no_csprng', $e->getMessage() );
		}

		$clients               = $this->clients();
		$clients[ $client_id ] = array(
			'client_name'   => (string) $client['client_name'],
			'redirect_uris' => array_values( array_map( 'strval', (array) $client['redirect_uris'] ) ),
			'auth_method'   => (string) $client['auth_method'],
			'secret_hash'   => null === $secret ? null : $this->tokens->hash( $secret ),
			'created_at'    => time(),
		);

		update_option( self::CLIENTS_OPTION, $this->cap_clients( $clients ), false );

		return array(
			'client_id'     => $client_id,
			'client_secret' => $secret,
		);
	}

	/**
	 * A registered client, or null.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id: string, client_name: string, redirect_uris: array<int, string>, auth_method: string, secret_hash: string|null}|null
	 */
	public function find_client( string $client_id ): ?array {
		if ( 0 !== strpos( $client_id, self::CLIENT_PREFIX ) ) {
			return null;
		}

		$row = $this->clients()[ $client_id ] ?? null;

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'client_id'     => $client_id,
			'client_name'   => (string) ( $row['client_name'] ?? '' ),
			'redirect_uris' => array_values( array_map( 'strval', (array) ( $row['redirect_uris'] ?? array() ) ) ),
			'auth_method'   => (string) ( $row['auth_method'] ?? 'none' ),
			'secret_hash'   => isset( $row['secret_hash'] ) ? (string) $row['secret_hash'] : null,
		);
	}

	/**
	 * Constant-time check of a presented client secret.
	 *
	 * @param string $secret      Presented secret.
	 * @param string $stored_hash Stored hash.
	 */
	public function verify_secret( string $secret, string $stored_hash ): bool {
		return '' !== $secret && $this->tokens->verify( $secret, $stored_hash );
	}

	/**
	 * Registered clients, client id => record.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function clients(): array {
		$clients = get_option( self::CLIENTS_OPTION, array() );

		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Keep the list short: clients that never signed in go after a week, and
	 * the oldest go first once there are more than CLIENTS_KEPT.
	 *
	 * @param array<string, array<string, mixed>> $clients Registered clients.
	 * @return array<string, array<string, mixed>>
	 */
	private function cap_clients( array $clients ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read on registration and the daily prune only.
		$in_use = array_flip( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT client_id FROM %i WHERE client_id LIKE %s', $this->table(), $wpdb->esc_like( self::CLIENT_PREFIX ) . '%' ) ) );
		$cutoff = time() - WEEK_IN_SECONDS;

		foreach ( $clients as $id => $row ) {
			if ( ! isset( $in_use[ $id ] ) && (int) ( $row['created_at'] ?? 0 ) < $cutoff ) {
				unset( $clients[ $id ] );
			}
		}

		uasort(
			$clients,
			static fn( array $a, array $b ): int => (int) ( $b['created_at'] ?? 0 ) <=> (int) ( $a['created_at'] ?? 0 )
		);

		return array_slice( $clients, 0, self::CLIENTS_KEPT, true );
	}

	/**
	 * A fresh grant id.
	 */
	public function new_grant_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * The tokens table.
	 */
	private function table(): string {
		return Installer::table( Installer::TABLE_OAUTH_TOKENS );
	}

	/**
	 * URL-safe random string.
	 *
	 * @param int $bytes Entropy in bytes.
	 * @throws \Exception When the platform has no CSPRNG.
	 */
	private static function random_string( int $bytes = 32 ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Transport encoding of random bytes.
		return rtrim( strtr( base64_encode( random_bytes( $bytes ) ), '+/', '-_' ), '=' );
	}
}
