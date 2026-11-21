<?php
/**
 * The `ai-agent-os/update-post-meta` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Site;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\MetaReader;
use AiAgentOs\Abilities\Support\MetaWriter;
use AiAgentOs\Abilities\Support\PostReader;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Sets one unprotected custom field (meta) on a post, page or item.
 */
final class UpdatePostMeta {

	/** Ability name. */
	public const NAME = 'ai-agent-os/update-post-meta';

	/** Capabilities lent for the call. */
	public const CAPS = GetPostMeta::CAPS;

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Edit a post’s extra fields', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Sets one custom field (meta) on a post, page or custom post type item, replacing its value. Protected keys (starting with an underscore), which WordPress and plugins keep for themselves, are refused.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-site',
			'input_schema'        => MetaWriter::input_schema( __( 'The post, page or item ID.', 'zorem-ai-agent-connector' ) ),
			'output_schema'       => MetaReader::schema(),
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
		$input = is_array( $input ) ? $input : array();
		$post  = PostReader::find( absint( $input['id'] ?? 0 ) );

		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return PostWriter::forbidden();
		}

		return MetaWriter::write( 'post', (int) $post->ID, (string) ( $input['key'] ?? '' ), (string) ( $input['value'] ?? '' ) );
	}
}
