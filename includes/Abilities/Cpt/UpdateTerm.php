<?php
/**
 * The `ai-agent-os/update-term` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Abilities\Support\TermWriter;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Renames a category, tag or custom taxonomy term, or changes its slug, description or parent.
 */
final class UpdateTerm {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-term';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'manage_categories' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		$properties = array( 'id' => Schema::id( __( 'The term ID.', 'zorem-ai-agent-connector' ) ) ) + TermWriter::properties( true );

		return array(
			'label'               => __( 'Rename or edit a category or tag', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Changes a category, tag or custom taxonomy term\'s name, slug, description or parent. Only the fields sent are changed. Changing a slug changes the term\'s web address.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input( $properties, array( 'id' ) ),
			'output_schema'       => PostProjection::term_schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_term( $input ),
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
		$input = is_array( $input ) ? $input : array();
		$term  = self::find( absint( $input['id'] ?? 0 ) );

		if ( $term instanceof \WP_Error ) {
			return $term;
		}

		$args = TermWriter::fields( (string) $term->taxonomy, $input );

		if ( $args instanceof \WP_Error ) {
			return $args;
		}

		if ( isset( $args['parent'] ) && (int) $args['parent'] === (int) $term->term_id ) {
			return new \WP_Error( 'ai_agent_os_invalid_parent', __( 'A term cannot be its own parent.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		if ( array() !== $args ) {
			$updated = wp_update_term( (int) $term->term_id, (string) $term->taxonomy, wp_slash( $args ) );

			if ( $updated instanceof \WP_Error ) {
				return $updated;
			}
		}

		$fresh = get_term( (int) $term->term_id, (string) $term->taxonomy );

		return $fresh instanceof \WP_Term ? PostProjection::term( $fresh ) : self::find( 0 );
	}

	/**
	 * A term of a supported taxonomy the current user may edit, or not found.
	 *
	 * @param int $id Term ID.
	 * @return \WP_Term|\WP_Error
	 */
	public static function find( int $id ) {
		$term = get_term( $id );

		if ( ! $term instanceof \WP_Term || ! ContentTypes::is_supported_taxonomy( (string) $term->taxonomy ) || ! current_user_can( 'edit_term', $term->term_id ) ) {
			return new \WP_Error( 'ai_agent_os_not_found', __( 'No term with that ID was found.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		return $term;
	}
}
