<?php
/**
 * The `ai-agent-os/get-term-meta` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Cpt;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\ContentTypes;
use AiAgentOs\Abilities\Support\Limits;
use AiAgentOs\Abilities\Support\MetaReader;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * A term's unprotected meta.
 */
final class GetTermMeta {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-term-meta';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'edit_posts' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'See extra category or tag details', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Returns the custom fields (meta) of a category, tag or custom taxonomy term. Values are text; structured values are JSON.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-cpt',
			'input_schema'        => Schema::input(
				array(
					'id'  => Schema::id( __( 'The term ID.', 'zorem-ai-agent-connector' ) ),
					'key' => MetaReader::key_property(),
				),
				array( 'id' )
			),
			'output_schema'       => MetaReader::schema(),
			'permission_callback' => static fn( $input = null ): bool => Access::can_edit_term( $input ),
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
		$input = is_array( $input ) ? $input : array();
		$id    = absint( $input['id'] ?? 0 );
		$term  = get_term( $id );

		if ( ! $term instanceof \WP_Term || ! ContentTypes::is_supported_taxonomy( (string) $term->taxonomy ) ) {
			return new \WP_Error( 'ai_agent_os_not_found', __( 'No term with that ID was found.', 'zorem-ai-agent-connector' ), array( 'status' => 404 ) );
		}

		// Checked again here: existing and being in a supported taxonomy is not permission to read its fields.
		if ( ! current_user_can( 'edit_term', $id ) ) {
			return new \WP_Error( 'ai_agent_os_forbidden', __( 'The custom fields of this term cannot be read with this ability.', 'zorem-ai-agent-connector' ), array( 'status' => 403 ) );
		}

		$key = mb_substr( (string) ( $input['key'] ?? '' ), 0, Limits::KEY_MAX );

		return array( 'id' => $id ) + MetaReader::read( 'term', $key, (array) get_term_meta( $id ) );
	}
}
