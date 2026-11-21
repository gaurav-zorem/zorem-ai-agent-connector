<?php
/**
 * Writing one post or term meta value for the agent.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one meta write path, and the keys it will not touch.
 *
 * A protected key is refused for every caller, the agent of an administrator
 * included: protected meta is where WordPress and plugins keep their own
 * state (edit locks, the page template, payment and licence data), and an
 * agent that can write it can make a plugin believe things that are not
 * true. WordPress's own `edit_post_meta` / `edit_term_meta` check runs as
 * well, so a key a plugin registered with a stricter auth callback stays
 * as strict as that plugin made it.
 *
 * Values are stored as text. A structured value cannot be written, so no
 * serialised object is ever created from the agent's input.
 */
final class MetaWriter {

	/**
	 * Input properties of a meta write.
	 *
	 * @param string $id_description What the ID identifies.
	 * @return array<string, mixed>
	 */
	public static function input_schema( string $id_description ): array {
		return Schema::input(
			array(
				'id'    => Schema::id( $id_description ),
				'key'   => Schema::key( __( 'The meta key to set. Keys starting with an underscore are protected and refused.', 'zorem-ai-agent-connector' ) ),
				'value' => array(
					'type'        => 'string',
					'maxLength'   => Limits::META_VALUE_MAX,
					'description' => __( 'The value, as text. It replaces every existing value of this key.', 'zorem-ai-agent-connector' ),
				),
			),
			array( 'id', 'key', 'value' )
		);
	}

	/**
	 * Set a meta value.
	 *
	 * @param string $type  `post` or `term`.
	 * @param int    $id    Object ID, already found and checked by the caller.
	 * @param string $key   Meta key.
	 * @param string $value Value.
	 * @return array<string, mixed>|\WP_Error The key's values after the write, as MetaReader returns them.
	 */
	public static function write( string $type, int $id, string $key, string $value ) {
		$key = mb_substr( $key, 0, Limits::KEY_MAX );

		if ( '' === trim( $key ) || sanitize_text_field( $key ) !== $key ) {
			return new \WP_Error( 'ai_agent_os_invalid_meta_key', __( 'That is not a valid meta key.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		if ( is_protected_meta( $key, $type ) ) {
			return new \WP_Error( 'ai_agent_os_protected_meta', __( 'That meta key is protected and cannot be changed by the agent.', 'zorem-ai-agent-connector' ), array( 'status' => 403 ) );
		}

		if ( ! current_user_can( 'edit_' . $type . '_meta', $id, $key ) ) {
			return PostWriter::forbidden();
		}

		$value = mb_substr( $value, 0, Limits::META_VALUE_MAX );

		$saved = 'post' === $type ? update_post_meta( $id, $key, wp_slash( $value ) ) : update_term_meta( $id, $key, wp_slash( $value ) );

		if ( $saved instanceof \WP_Error ) {
			return $saved;
		}

		$raw = 'post' === $type ? get_post_meta( $id ) : get_term_meta( $id );

		return array( 'id' => $id ) + MetaReader::read( $type, $key, (array) $raw );
	}
}
