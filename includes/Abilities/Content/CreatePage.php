<?php
/**
 * The `ai-agent-os/create-page` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Content;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Access;

use AiAgentOs\Abilities\Support\PostProjection;
use AiAgentOs\Abilities\Support\PostWriter;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Creates a page, as a draft unless told otherwise.
 */
final class CreatePage {

	/** Ability name. */
	public const NAME = 'ai-agent-os/create-page';

	/** Capabilities lent for the call: publishing, and reading the parent page. */
	public const CAPS = array( 'edit_pages', 'publish_pages', 'edit_others_pages', 'edit_published_pages' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Create page', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Creates a page with title, content, parent, template and featured image. It is saved as a draft unless status says otherwise. To change a page later, use update-cpt-item with its ID.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-content',
			'input_schema'        => Schema::input( PostWriter::properties( true ) + PostWriter::hierarchy_properties(), array( 'title' ) ),
			'output_schema'       => PostProjection::full_schema(),
			'permission_callback' => static fn(): bool => Access::can_create_in( 'page' ),
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
		return PostWriter::create( 'page', is_array( $input ) ? $input : array() );
	}
}
