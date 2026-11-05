<?php
/**
 * The `ai-agent-os/get-categories` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Abilities\Support\TermReader;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Lists post categories.
 */
final class GetCategories {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-categories';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get categories', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists post categories by name, with ID, slug, description, parent and post count.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( TermReader::list_properties( true ) ),
			'output_schema'       => Schema::page_of( 'terms', PostProjection::term_schema() ),
			'permission_callback' => static fn(): bool => Access::can_read_taxonomy( 'category' ),
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
		return TermReader::list_terms( 'category', is_array( $input ) ? $input : array() );
	}
}
