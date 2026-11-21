<?php
/**
 * The `ai-agent-os/get-users` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Users;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Limits;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Core\Installer;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Lists the site's users: ID, name, email and roles.
 *
 * What it never returns: login names, password hashes, activation keys,
 * capabilities or any user meta. Agent shadow users are left out; they are
 * not people. Email and name sit under keys the PII masker knows
 * (`user_email`, `display_name`).
 *
 * Search looks at the display name and the URL slug only. Searching by email
 * would let the agent whose results are masked confirm whether an address it
 * already has belongs to a user.
 */
final class GetUsers {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-users';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'list_users' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See the user list', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists site users by display name, with ID, email and roles. Useful for choosing a post author.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-users',
			'input_schema'        => Schema::input(
				array(
					'role'   => Schema::key( __( 'Only users with this role slug, for example "editor".', 'zorem-ai-agent-connector' ) ),
					'search' => Schema::search(),
				) + Schema::paging()
			),
			'output_schema'       => Schema::page_of(
				'users',
				array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array( 'type' => 'integer' ),
						'display_name' => array( 'type' => 'string' ),
						'user_email'   => array( 'type' => 'string' ),
						'roles'        => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				)
			),
			'permission_callback' => static fn(): bool => current_user_can( 'list_users' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		$input = is_array( $input ) ? $input : array();

		list( $page, $per_page ) = Schema::paging_from( $input );

		$args = array(
			'number'       => $per_page,
			'paged'        => $page,
			'orderby'      => 'display_name',
			'order'        => 'ASC',
			'count_total'  => true,
			'role__not_in' => array( Installer::AGENT_ROLE ),
		);

		if ( isset( $input['role'] ) ) {
			$role = sanitize_key( (string) $input['role'] );

			if ( null === get_role( $role ) ) {
				return new \WP_Error( 'ai_agent_os_unknown_role', __( 'There is no role with that slug.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
			}

			$args['role'] = $role;
		}

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['search']         = '*' . mb_substr( sanitize_text_field( (string) $input['search'] ), 0, Limits::SEARCH_MAX ) . '*';
			$args['search_columns'] = array( 'display_name', 'user_nicename' );
		}

		$query = new \WP_User_Query( $args );
		$users = array();

		foreach ( (array) $query->get_results() as $user ) {
			if ( $user instanceof \WP_User ) {
				$users[] = array(
					'id'           => (int) $user->ID,
					'display_name' => (string) $user->display_name,
					'user_email'   => (string) $user->user_email,
					'roles'        => array_values( array_map( 'strval', $user->roles ) ),
				);
			}
		}

		return Schema::page_result( 'users', $users, (int) $query->get_total(), $page, $per_page );
	}
}
