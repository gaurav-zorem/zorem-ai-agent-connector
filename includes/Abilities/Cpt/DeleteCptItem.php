<?php
/**
 * The `ai-agent-os/delete-cpt-item` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostTrasher;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Moves a page or custom post type item to the trash. Never deletes it permanently.
 */
final class DeleteCptItem {

	/** Ability name. */
	public const NAME = 'ai-agent-os/delete-cpt-item';

	/** Capabilities lent for the call: finding the item, then trashing it. */
	public const CAPS = array(
		...PostReader::POST_READ_CAPS,
		...PostReader::PAGE_READ_CAPS,
		'delete_posts',
		'delete_others_posts',
		'delete_published_posts',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
	);

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Move an item to the trash', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Moves a page or custom post type item to the trash, where a person can restore it. It is never deleted permanently.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The item ID, from get-cpt-items or get-pages.', 'zorem-ai-agent-connector' ) ) ),
				array( 'id' )
			),
			'output_schema'       => PostTrasher::schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_delete_post( $input ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_DESTRUCTIVE, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		return PostTrasher::trash( absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 ), array() );
	}
}
