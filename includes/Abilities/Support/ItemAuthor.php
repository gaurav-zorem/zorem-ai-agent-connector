<?php
/**
 * Who a new item written by the agent is credited to.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\ShadowUserManager;
use AiAgentOs\Auth\AgentContext;

/**
 * Credits a new post, page or item to the administrator behind the agent.
 *
 * The agent acts through its own hidden account, so without this WordPress
 * credits everything it writes to that account and the site's bylines show
 * the agent's name. The person credited instead is the administrator who
 * approved the app's sign-in, else the one who set the agent up. The activity
 * log still records the agent as the one who wrote it; only the author changes.
 *
 * The agent never chooses: abilities take no author input, so it cannot
 * credit its work to anyone else. The person must still exist, must not be an
 * agent, and must be able to edit items of that type; otherwise the agent's
 * own account keeps it, as before.
 */
final class ItemAuthor {

	/**
	 * The user a new item of this type is credited to, or 0 to leave it to WordPress (the agent's account).
	 *
	 * @param \WP_Post_Type $type The new item's post type.
	 */
	public static function for_new_item( \WP_Post_Type $type ): int {
		$agent = AgentContext::get();

		if ( ! $agent instanceof Agent || ! post_type_supports( $type->name, 'author' ) ) {
			return 0;
		}

		$oauth   = (array) $agent->meta( 'oauth', array() );
		$user_id = (int) ( $oauth['approved_by'] ?? 0 );
		$user_id = $user_id > 0 ? $user_id : $agent->get_created_by();

		/**
		 * Filters who a new item written by the agent is credited to.
		 *
		 * Return 0 to credit it to the agent's own account.
		 *
		 * @param int           $user_id The administrator behind the agent, or 0.
		 * @param Agent         $agent   The agent writing it.
		 * @param \WP_Post_Type $type    The item's post type.
		 */
		$user_id = (int) apply_filters( 'ai_agent_os_new_item_author', $user_id, $agent, $type );

		return self::can_be_credited( $user_id, $type ) ? $user_id : 0;
	}

	/**
	 * Whether a user may be named as the author of an item of this type.
	 *
	 * @param int           $user_id User id.
	 * @param \WP_Post_Type $type    Post type.
	 */
	private static function can_be_credited( int $user_id, \WP_Post_Type $type ): bool {
		return $user_id > 0
			&& get_userdata( $user_id ) instanceof \WP_User
			&& ! ShadowUserManager::is_agent_user( $user_id )
			&& user_can( $user_id, $type->cap->edit_posts );
	}
}
