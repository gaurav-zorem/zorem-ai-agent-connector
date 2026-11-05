<?php
/**
 * Shadow user lifecycle.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

use AiAgentOs\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and hides the WP user that backs the agent.
 *
 * An agent is not a user, but a great deal of WordPress and WooCommerce code
 * assumes somebody is logged in — current_user_can(), post authorship, Woo's
 * own permission callbacks. The shadow user exists purely to satisfy that
 * assumption. It holds no standing privilege: the role grants 'read' and
 * nothing else, and any capability an agent needs is granted at the moment of
 * the call by CapabilityBridge and withdrawn immediately after.
 *
 * These users are hidden from the admin, the Users screen included, because
 * they are not people. Showing them in Users would invite an admin to edit
 * or promote one, which is exactly the coupling this design exists to
 * prevent. The account is managed with the agent, on its screen.
 */
final class ShadowUserManager {

	/**
	 * Meta key marking a user as an agent's shadow.
	 */
	public const AGENT_META_KEY = 'ai_agent_os_agent_uuid';

	/**
	 * Cache key for the list of agent account ids.
	 */
	private const IDS_CACHE_KEY = 'ai_agent_os_shadow_user_ids';

	/**
	 * Cache group for this plugin's lookups.
	 */
	private const CACHE_GROUP = 'ai_agent_os';

	/**
	 * Agent account ids for this request, once looked up.
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $agent_user_ids = null;

	/**
	 * Register the hooks that keep shadow users out of sight.
	 */
	public function register(): void {
		add_action( 'pre_get_users', array( $this, 'exclude_from_queries' ) );
		add_filter( 'rest_user_query', array( $this, 'exclude_from_rest_query' ) );
		add_filter( 'views_users', array( $this, 'hide_from_role_views' ) );

		// The id list is read from meta, so it changes when an account is made or removed.
		add_action( 'ai_agent_os_agent_created', array( self::class, 'flush_agent_user_ids' ) );
		add_action( 'ai_agent_os_agent_deleted', array( self::class, 'flush_agent_user_ids' ) );

		// An agent that stops acting loses every credential its account holds, not only its token.
		add_action( 'ai_agent_os_agent_revoked', array( $this, 'drop_credentials' ) );
		add_action( 'ai_agent_os_agent_deleted', array( $this, 'drop_credentials' ) );
		add_action(
			'ai_agent_os_agent_status_changed',
			function ( Agent $before, Agent $after ): void {
				if ( $before->is_active() && ! $after->is_active() ) {
					$this->drop_credentials( $after );
				}
			},
			10,
			2
		);
	}

	/**
	 * Delete the application passwords on an agent's account.
	 *
	 * Reactivating the agent does not bring them back: whoever needs one
	 * creates a new one.
	 *
	 * @param Agent $agent The agent.
	 */
	public function drop_credentials( Agent $agent ): void {
		$user_id = $agent->get_shadow_user_id();

		if ( $user_id > 0 && class_exists( \WP_Application_Passwords::class ) && self::is_agent_user( $user_id ) ) {
			\WP_Application_Passwords::delete_all_application_passwords( $user_id );
		}
	}

	/**
	 * Whether a user is an agent's account, by its link to the agent.
	 *
	 * The link is meta only this plugin writes. The role is not used: an
	 * administrator, or a plugin, can change a role, and that must not turn
	 * an agent's account into an ordinary one.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_agent_user( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, self::AGENT_META_KEY, true );
	}

	/**
	 * The ids of every account that backs an agent.
	 *
	 * Read from the meta, not the role, because that is what marks an account
	 * as an agent's — and because the role can be changed by an administrator
	 * or by a role-editing plugin. Hiding on the role alone meant a role change
	 * put the account back in the Users list.
	 *
	 * Queried directly rather than through `get_users()`: this is called from
	 * inside `pre_get_users`, and a `WP_User_Query` here would re-enter the
	 * filter that asked for it. One indexed read per request, cached.
	 *
	 * @return array<int, int>
	 */
	public static function agent_user_ids(): array {
		if ( null !== self::$agent_user_ids ) {
			return self::$agent_user_ids;
		}

		$cached = wp_cache_get( self::IDS_CACHE_KEY, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			self::$agent_user_ids = array_map( 'intval', $cached );

			return self::$agent_user_ids;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API reads user ids by meta key without running a user query, which would recurse; cached below.
		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", self::AGENT_META_KEY )
		);

		self::$agent_user_ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );

		wp_cache_set( self::IDS_CACHE_KEY, self::$agent_user_ids, self::CACHE_GROUP );

		return self::$agent_user_ids;
	}

	/**
	 * Forget the cached id list. Called when an agent's account is made or removed.
	 */
	public static function flush_agent_user_ids(): void {
		self::$agent_user_ids = null;

		wp_cache_delete( self::IDS_CACHE_KEY, self::CACHE_GROUP );
	}

	/**
	 * Create the shadow user for an agent.
	 *
	 * @param string $agent_uuid Agent uuid.
	 * @param string $agent_name Agent display name.
	 * @return int|\WP_Error New user id.
	 */
	public function create( string $agent_uuid, string $agent_name ) {
		$suffix = substr( str_replace( '-', '', $agent_uuid ), 0, 12 );
		$login  = 'zorem-agent-' . $suffix;

		if ( username_exists( $login ) ) {
			$login .= '-' . wp_generate_password( 4, false, false );
		}

		/*
		 * The password is never used: agents authenticate by token, and the
		 * account cannot log in interactively in any meaningful way. It is set
		 * to a long random value so the account is not passwordless.
		 */
		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'user_email'   => $this->synthetic_email( $suffix ),
				'display_name' => sprintf(
					/* translators: %s: agent name. */
					__( '%s (agent)', 'zorem-ai-agent-connector' ),
					$agent_name
				),
				'nickname'     => $agent_name,
				'role'         => Installer::AGENT_ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::AGENT_META_KEY, $agent_uuid );
		update_user_meta( $user_id, 'show_admin_bar_front', 'false' );

		return (int) $user_id;
	}

	/**
	 * Delete an agent's shadow user.
	 *
	 * Refuses to touch anything that is not one of ours.
	 *
	 * @param int $user_id Shadow user id.
	 */
	public function delete( int $user_id ): bool {
		if ( $user_id < 1 || ! $this->is_shadow_user( $user_id ) ) {
			return false;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		return (bool) wp_delete_user( $user_id, self::heir() );
	}

	/**
	 * Who inherits what an agent created when its shadow user is deleted.
	 *
	 * Without a reassign target, `wp_delete_user()` permanently deletes the
	 * user's attachments and other author-supporting content (product images
	 * an agent uploaded, for example) and trashes its posts. The first
	 * administrator keeps them instead. Null only on a site with no
	 * administrator, where WordPress's own behaviour is the only option.
	 */
	public static function heir(): ?int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		return array() === $admins ? null : (int) $admins[0];
	}

	/**
	 * Whether a user id belongs to an agent.
	 *
	 * @param int $user_id User id.
	 */
	public function is_shadow_user( int $user_id ): bool {
		return self::is_agent_user( $user_id );
	}

	/**
	 * A deliverable-looking but unroutable address.
	 *
	 * WordPress requires an email and enforces uniqueness. Using the site's own
	 * host with a reserved local part keeps it valid without risking mail to a
	 * real inbox.
	 *
	 * @param string $suffix Short unique fragment.
	 */
	private function synthetic_email( string $suffix ): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? $host : 'example.invalid';

		return 'zorem-agent+' . $suffix . '@' . $host;
	}

	/**
	 * Keep shadow users out of user queries, the Users screen included.
	 *
	 * An agent is managed on its own screen, where its account is made and
	 * removed with it; listing the account again among the site's people only
	 * invites editing it by hand. WP-CLI and our own screens still see them
	 * through `ai_agent_os_include_shadow_users`.
	 *
	 * Excluded two ways, by id and by role. The id list is the one that
	 * decides: it comes from the meta that marks an account as an agent's, so
	 * it holds whatever the account's role has since been changed to. The role
	 * exclusion stays because it costs nothing and still covers an account
	 * created later in the same request than the id list was read.
	 *
	 * @param \WP_User_Query $query The query.
	 */
	public function exclude_from_queries( \WP_User_Query $query ): void {
		if ( $this->should_include_agents() ) {
			return;
		}

		$excluded = $query->get( 'role__not_in' );
		$excluded = is_array( $excluded ) ? $excluded : ( $excluded ? array( $excluded ) : array() );

		if ( ! in_array( Installer::AGENT_ROLE, $excluded, true ) ) {
			$excluded[] = Installer::AGENT_ROLE;
			$query->set( 'role__not_in', $excluded );
		}

		$agent_ids = self::agent_user_ids();

		if ( array() === $agent_ids ) {
			return;
		}

		$exclude = $query->get( 'exclude' );
		$exclude = is_array( $exclude ) ? $exclude : ( $exclude ? array( $exclude ) : array() );

		$query->set( 'exclude', array_values( array_unique( array_merge( array_map( 'intval', $exclude ), $agent_ids ) ) ) );
	}

	/**
	 * Same exclusion for the REST users endpoint.
	 *
	 * @param array<string, mixed> $args WP_User_Query args.
	 * @return array<string, mixed>
	 */
	public function exclude_from_rest_query( array $args ): array {
		if ( $this->should_include_agents() ) {
			return $args;
		}

		$excluded = $args['role__not_in'] ?? array();
		$excluded = is_array( $excluded ) ? $excluded : array( $excluded );

		$excluded[]           = Installer::AGENT_ROLE;
		$args['role__not_in'] = array_values( array_unique( $excluded ) );

		$agent_ids = self::agent_user_ids();

		if ( array() !== $agent_ids ) {
			$exclude = $args['exclude'] ?? array();
			$exclude = is_array( $exclude ) ? $exclude : array( $exclude );

			/*
			 * `exclude` on a user query, not `post__not_in` on a post query:
			 * the id list is this site's agent accounts, normally one, matched
			 * on the users table's primary key.
			 */
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- A WP_User_Query argument; see above.
			$args['exclude'] = array_values( array_unique( array_merge( array_map( 'intval', $exclude ), $agent_ids ) ) );
		}

		return $args;
	}

	/**
	 * Drop the agents' role from the Users screen's role links, and from the "All" count.
	 *
	 * The links are counted by count_users(), which no query filter reaches,
	 * so without this the screen would say "All (26)" over 25 rows and offer
	 * a role link that lists nothing.
	 *
	 * @param array<string, string> $views Role links, role => HTML.
	 * @return array<string, string>
	 */
	public function hide_from_role_views( array $views ): array {
		if ( $this->should_include_agents() ) {
			return $views;
		}

		unset( $views[ Installer::AGENT_ROLE ] );

		/*
		 * Counted from the account list, not from the role tally: an account
		 * whose role was changed is still an agent's and still hidden from the
		 * rows, so counting the role would leave "All" one too high above a
		 * list one row shorter.
		 */
		$counts = count_users();
		$agents = count( self::agent_user_ids() );

		if ( $agents > 0 && isset( $views['all'] ) ) {
			$people       = max( 0, (int) $counts['total_users'] - $agents );
			$views['all'] = (string) preg_replace(
				'#(<span class="count">\()[^)]*(\)</span>)#',
				'${1}' . number_format_i18n( $people ) . '${2}',
				$views['all'],
				1
			);
		}

		return $views;
	}

	/**
	 * Whether the current code path legitimately wants to see the agent.
	 *
	 * Our own screens and WP-CLI do; the Users list does not.
	 */
	private function should_include_agents(): bool {
		/**
		 * Filters whether shadow agent users are visible to user queries.
		 *
		 * @param bool $include Whether to include agent users.
		 */
		return (bool) apply_filters( 'ai_agent_os_include_shadow_users', false );
	}
}
