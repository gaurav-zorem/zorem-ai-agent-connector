<?php
/**
 * The `ai-agent-os/create-tag` ability.
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
 * Creates a post tag.
 */
final class CreateTag {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-tag';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'manage_categories' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Create tag', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates a post tag with name, slug and description. Fails if a tag with that name already exists; use get-tags to find it.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( TermWriter::properties( false ), array( 'name' ) ),
			'output_schema'       => PostProjection::term_schema(),
			'permission_callback' => static fn(): bool => Access::can_manage_taxonomy( 'post_tag' ),
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
		return TermWriter::create( 'post_tag', is_array( $input ) ? $input : array() );
	}
}
