<?php
/**
 * The `ai-agent-os/get-pages` ability.
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
 * Lists pages, newest first, with an excerpt but no full content.
 */
final class GetPages {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-pages';

	/** Capabilities lent for the call. */
	public const CAPS = PostReader::PAGE_READ_CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See pages', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists pages (published, draft, pending or scheduled), newest first, with title, status, link, parent and excerpt. Call get-page for the full content.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-pages',
			'input_schema'        => Schema::input( PostReader::list_properties( true ) ),
			'output_schema'       => Schema::page_of( 'items', PostProjection::summary_schema() ),
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
		return PostReader::list_items( 'page', is_array( $input ) ? $input : array() );
	}
}
