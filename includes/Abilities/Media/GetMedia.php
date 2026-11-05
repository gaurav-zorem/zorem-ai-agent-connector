<?php
/**
 * The `ai-agent-os/get-media` ability.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Media;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Abilities\Support\Limits;
use AiAgentOs\Abilities\Support\Schema;
use AiAgentOs\Governance\RiskClassifier;

/**
 * Lists the media library, newest first.
 */
final class GetMedia {

	/** Ability name. */
	public const NAME = 'ai-agent-os/get-media';

	/** Capabilities lent for the call. */
	public const CAPS = array( 'upload_files' );

	/** The `mime` filter's values: the top-level MIME types WordPress groups by. */
	private const MIME_GROUPS = array( 'image', 'video', 'audio', 'application', 'text' );

	/**
	 * The registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Get media', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists media library files, newest first, with URL, MIME type, title, alt text, caption, dimensions and the post each is attached to.', 'zorem-ai-agent-connector' ),
			'category'            => 'ai-agent-os-media',
			'input_schema'        => Schema::input(
				array(
					'search' => Schema::search(),
					'mime'   => array(
						'type'        => 'string',
						'enum'        => self::MIME_GROUPS,
						'description' => __( 'Only files of this kind, for example "image".', 'zorem-ai-agent-connector' ),
					),
				) + Schema::paging()
			),
			'output_schema'       => Schema::page_of( 'media', self::item_schema() ),
			'permission_callback' => static fn(): bool => current_user_can( 'upload_files' ),
			'execute_callback'    => array( self::class, 'execute' ),
			'meta'                => Schema::meta( RiskClassifier::RISK_READ, self::CAPS ),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array<string, mixed>|null $input Ability input.
	 * @return array<string, mixed>
	 */
	public static function execute( $input = null ): array {
		$input = is_array( $input ) ? $input : array();

		list( $page, $per_page ) = Schema::paging_from( $input );

		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( isset( $input['mime'] ) && in_array( $input['mime'], self::MIME_GROUPS, true ) ) {
			$args['post_mime_type'] = (string) $input['mime'];
		}

		if ( isset( $input['search'] ) && '' !== trim( (string) $input['search'] ) ) {
			$args['s'] = mb_substr( sanitize_text_field( (string) $input['search'] ), 0, Limits::SEARCH_MAX );
		}

		$query = new \WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $attachment ) {
			if ( $attachment instanceof \WP_Post ) {
				$items[] = self::project( $attachment );
			}
		}

		return Schema::page_result( 'media', $items, (int) $query->found_posts, $page, $per_page );
	}

	/**
	 * One attachment as the media abilities return it.
	 *
	 * @param \WP_Post $attachment The attachment.
	 * @return array<string, mixed>
	 */
	public static function project( \WP_Post $attachment ): array {
		$metadata = wp_get_attachment_metadata( $attachment->ID );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$url      = wp_get_attachment_url( $attachment->ID );

		return array(
			'id'          => (int) $attachment->ID,
			'title'       => (string) get_the_title( $attachment ),
			'url'         => false === $url ? '' : (string) $url,
			'mime_type'   => (string) $attachment->post_mime_type,
			'alt'         => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
			'caption'     => (string) $attachment->post_excerpt,
			'width'       => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'      => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'attached_to' => (int) $attachment->post_parent,
			'date_gmt'    => mysql2date( 'Y-m-d\TH:i:s\Z', (string) $attachment->post_date_gmt, false ),
		);
	}

	/**
	 * Schema of project().
	 *
	 * @return array<string, mixed>
	 */
	public static function item_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'          => array( 'type' => 'integer' ),
				'title'       => array( 'type' => 'string' ),
				'url'         => array( 'type' => 'string' ),
				'mime_type'   => array( 'type' => 'string' ),
				'alt'         => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'width'       => array( 'type' => array( 'integer', 'null' ) ),
				'height'      => array( 'type' => array( 'integer', 'null' ) ),
				'attached_to' => array( 'type' => 'integer' ),
				'date_gmt'    => array( 'type' => 'string' ),
			),
		);
	}
}
