<?php
/**
 * The `ai-agent-os/get-page` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * One page with its full content, template and parent.
 */
final class GetPage {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-page';

	/** Capabilities lent for the call. */
	public const CAPS = PostReader::PAGE_READ_CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Open a page', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Returns one page by ID: title, status, full content (block markup), parent, template and featured image ID. Password-protected pages return no content.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-pages',
			'input_schema'        => Schema::input(
				array( 'id' => Schema::id( __( 'The page ID, from get-pages.', 'zorem-ai-agent-connector' ) ) ),
				array( 'id' )
			),
			'output_schema'       => PostProjection::full_schema(),

			/*
			 * Coarse here on purpose. `PostReader::find()` makes the
			 * per-object `read_post` check and answers every refusal with
			 * "not found", so the agent that may not read an item learns
			 * nothing about whether it exists. Refusing at the permission
			 * stage instead would answer "no permission", which tells the
			 * caller the item is there.
			 */
			'permission_callback' => static fn(): bool => Access::can_list( 'page' ),
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
		$post = PostReader::find( absint( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 ), array( 'page' ) );

		return $post instanceof \WP_Error ? $post : PostProjection::full( $post );
	}
}
