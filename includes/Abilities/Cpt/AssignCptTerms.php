<?php
/**
 * The `ai-agent-os/assign-cpt-terms` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Content\AssignPostTerms;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Sets the terms of a page or custom post type item in one supported taxonomy.
 */
final class AssignCptTerms {

	/** Ability name. */
	public const NAME = 'ai-agent-os/assign-cpt-terms';

	/** Capabilities lent for the call. */
	public const CAPS = array( ...PostReader::POST_READ_CAPS, ...PostReader::PAGE_READ_CAPS );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Assign terms to custom post type item', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Sets an item\'s terms in one of its taxonomies (from get-cpt-taxonomies), replacing them unless append is true.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => AssignPostTerms::input_schema( __( 'The item ID, from get-cpt-items.', 'zorem-ai-agent-connector' ) ),
			'output_schema'       => AssignPostTerms::output_schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_post( $input ),
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
		return AssignPostTerms::assign( is_array( $input ) ? $input : array(), array() );
	}
}
