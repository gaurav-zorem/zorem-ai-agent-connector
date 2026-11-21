<?php
/**
 * The `ai-agent-os/list-post-types` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Which post types the content abilities work with, and which they do not and why.
 */
final class ListPostTypes {

	/** Ability name. */
	public const NAME = 'ai-agent-os/list-post-types';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See which content types the site has', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists the post types the content abilities can read and change (posts, pages and custom post types), with their taxonomies, and the ones they cannot, with the reason. Call this before using a custom post type.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input( array() ),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'supported' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'         => array( 'type' => 'string' ),
								'label'        => array( 'type' => 'string' ),
								'hierarchical' => array( 'type' => 'boolean' ),
								'taxonomies'   => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
					'excluded'  => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'   => array( 'type' => 'string' ),
								'label'  => array( 'type' => 'string' ),
								'reason' => array( 'type' => 'string' ),
							),
						),
					),
				),
			),
			'permission_callback' => static fn(): bool => Access::can_list_any(),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * Excluded types are listed only when they have an admin screen: the
	 * dozen internal types WordPress registers are not something a person
	 * would ask the agent about, and listing them would bury the ones that are.
	 *
	 * @return array<string, mixed>
	 */
	public static function execute(): array {
		$supported = array();

		foreach ( ContentTypes::supported() as $name => $object ) {
			$supported[] = array(
				'name'         => $name,
				'label'        => (string) $object->labels->name,
				'hierarchical' => (bool) $object->hierarchical,
				'taxonomies'   => array_keys( ContentTypes::taxonomies_of( $name ) ),
			);
		}

		$excluded = array();

		foreach ( ContentTypes::excluded() as $name => $reason ) {
			$object = get_post_type_object( $name );

			if ( ! $object instanceof \WP_Post_Type || ! $object->show_ui ) {
				continue;
			}

			$excluded[] = array(
				'name'   => $name,
				'label'  => (string) $object->labels->name,
				'reason' => $reason,
			);
		}

		return array(
			'supported' => $supported,
			'excluded'  => $excluded,
		);
	}
}
