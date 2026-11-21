<?php
/**
 * Reading post and term meta for the agent.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Meta as the read abilities return it: unprotected keys only, values as text.
 *
 * A protected key (one is_protected_meta() says is protected, by default any
 * key starting with `_`) is how WordPress and plugins mark internals: edit
 * locks, payment and licence state, cached data. None of it is the agent's
 * business, and a read must not become the way to collect it.
 */
final class MetaReader {

	/**
	 * Unprotected meta, key => values.
	 *
	 * @param string                   $type `post` or `term`.
	 * @param string                   $key  One key, or '' for all.
	 * @param array<int|string, mixed> $raw  What get_post_meta()/get_term_meta() returned for all keys.
	 * @return array{meta: array<string, array<int, string>>, truncated: bool, omitted_protected: int}
	 */
	public static function read( string $type, string $key, array $raw ): array {
		$meta      = array();
		$truncated = false;
		$protected = 0;

		foreach ( $raw as $meta_key => $values ) {
			$meta_key = (string) $meta_key;

			if ( '' !== $key && $meta_key !== $key ) {
				continue;
			}

			if ( is_protected_meta( $meta_key, $type ) ) {
				++$protected;
				continue;
			}

			if ( count( $meta ) >= Limits::META_KEYS_MAX ) {
				$truncated = true;
				break;
			}

			foreach ( (array) $values as $value ) {
				$text = self::as_text( self::unserialize( $value ) );

				if ( mb_strlen( $text ) > Limits::META_VALUE_MAX ) {
					$text      = mb_substr( $text, 0, Limits::META_VALUE_MAX );
					$truncated = true;
				}

				$meta[ $meta_key ][] = $text;
			}
		}

		return array(
			'meta'              => $meta,
			'truncated'         => $truncated,
			'omitted_protected' => $protected,
		);
	}

	/**
	 * The output schema of a meta read.
	 *
	 * @return array<string, mixed>
	 */
	public static function schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'id'                => array( 'type' => 'integer' ),
				'meta'              => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'truncated'         => array( 'type' => 'boolean' ),
				'omitted_protected' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * The input `key` property.
	 *
	 * @return array<string, mixed>
	 */
	public static function key_property(): array {
		return Schema::key( __( 'Return only this meta key. Leave out for every key. Keys starting with an underscore are protected and never returned.', 'zorem-ai-agent-connector' ) );
	}

	/**
	 * A stored value, unserialised without instantiating any class.
	 *
	 * WordPress's maybe_unserialize() would build whatever objects the stored string
	 * names, which runs their magic methods. A read for the agent has no use
	 * for live objects, so they come back as __PHP_Incomplete_Class and are
	 * reported as JSON of their properties.
	 *
	 * @param mixed $value The raw stored value.
	 * @return mixed
	 */
	private static function unserialize( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}

		// No classes are allowed, so nothing is instantiated; a malformed value is returned as stored, below.
		$unserialized = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged

		return false === $unserialized && 'b:0;' !== trim( $value ) ? $value : $unserialized;
	}

	/**
	 * A stored value as text: scalars as they are, anything structured as JSON.
	 *
	 * @param mixed $value The stored value.
	 */
	private static function as_text( $value ): string {
		if ( is_scalar( $value ) || null === $value ) {
			return is_bool( $value ) ? ( $value ? '1' : '' ) : (string) $value;
		}

		$json = wp_json_encode( $value );

		return false === $json ? '' : $json;
	}
}
