<?php
/**
 * The `ai-agent-os/create-category` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Abilities\Support\TermWriter;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Creates a post category.
 */
final class CreateCategory {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-category';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'manage_categories' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Add a blog category', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates a post category with name, slug, description and parent. Fails if a category with that name already exists under the same parent.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( TermWriter::properties( true ), array( 'name' ) ),
			'output_schema'       => PostProjection::term_schema(),
			'permission_callback' => static fn(): bool => Access::can_manage_taxonomy( 'category' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_WRITE, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function execute( $input = null ) {
		return TermWriter::create( 'category', is_array( $input ) ? $input : array() );
	}
}
