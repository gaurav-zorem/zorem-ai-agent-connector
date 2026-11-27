<?php
/**
 * Brain-wide settings.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1;

defined( 'ABSPATH' ) || exit;

/**
 * Settings that belong to the shared Brain, not to either plugin.
 *
 * "Delete Brain data on uninstall" lives here rather than in a plugin's own
 * settings because the data is shared: one plugin's choice must not delete
 * what the other still uses (decision D-11).
 */
final class Settings {

	/** Option name. */
	public const OPTION = 'zorem_agent_brain_settings';

	/**
	 * Defaults. Every key a setting can have is listed here.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(
		'delete_data_on_uninstall' => false,

		/*
		 * `knowledge_enabled` gates plugin extraction entirely.
		 *
		 * `knowledge_curated_kb` reads the curated registry bundled with the
		 * kit. On by default: nothing leaves the server, because the only
		 * adapter wired reads `registry/` from disk. An HTTP-backed catalog
		 * would need its own consent; this flag would not be enough.
		 */
		'knowledge_enabled'        => true,
		'knowledge_curated_kb'     => true,

	);

	/**
	 * All settings, defaults merged in. Always read from the option.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = null ) {
		return self::all()[ $key ] ?? $default_value;
	}

	/**
	 * A boolean setting. Unknown keys are false.
	 *
	 * Callable on an instance too (`$settings->bool()`), which is how the
	 * ported pipeline classes, handed a Settings object, read it.
	 *
	 * @param string $key Setting name.
	 */
	public static function bool( string $key ): bool {
		return (bool) self::get( $key, false );
	}

	/**
	 * Merge changes. Unknown keys are ignored; values take the type of their default.
	 *
	 * @param array<string, mixed> $changes Key => value.
	 * @return array<string, mixed> Stored settings after the write.
	 */
	public static function update( array $changes ): array {
		$stored = self::all();

		foreach ( $changes as $key => $value ) {
			if ( ! array_key_exists( $key, self::DEFAULTS ) ) {
				continue;
			}

			$stored[ $key ] = is_bool( self::DEFAULTS[ $key ] ) ? (bool) rest_sanitize_boolean( $value ) : sanitize_text_field( (string) $value );
		}

		update_option( self::OPTION, $stored, false );

		return $stored;
	}
}
