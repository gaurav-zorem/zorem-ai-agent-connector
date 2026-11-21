<?php
/**
 * The `ai-agent-os/update-term-meta` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\MetaReader;
use AiAgentOs\Abilities\Support\MetaWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Sets one unprotected custom field (meta) on a term.
 */
final class UpdateTermMeta {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-term-meta';

	/** Capabilities lent for the call: `edit_term` maps to `manage_categories` for every supported taxonomy. */
	public const CAPS = array( 'manage_categories' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Edit extra category or tag details', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Sets one custom field (meta) on a category, tag or custom taxonomy term, replacing its value. Protected keys (starting with an underscore) are refused.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => MetaWriter::input_schema( __( 'The term ID.', 'zorem-ai-agent-connector' ) ),
			'output_schema'       => MetaReader::schema(),
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
		$term  = UpdateTerm::find( absint( $input['id'] ?? 0 ) );

		if ( $term instanceof \WP_Error ) {
			return $term;
		}

		return MetaWriter::write( 'term', (int) $term->term_id, (string) ( $input['key'] ?? '' ), (string) ( $input['value'] ?? '' ) );
	}
}
