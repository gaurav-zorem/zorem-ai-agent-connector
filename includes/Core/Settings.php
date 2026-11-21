<?php
/**
 * Plugin settings.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * One option, defaults merged in, sanitised by type on write.
 */
final class Settings {

	/**
	 * Option name.
	 */
	public const OPTION = 'ai_agent_os_settings';

	/**
	 * Defaults. Every key a setting can have is listed here.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = array(

		/*
		 * MCP: the agent's endpoint is on; the MCP Adapter's own default
		 * server, which would expose every ability to any logged-in user with
		 * the right capability, is off.
		 */
		'mcp_enabled'              => true,

		'delete_data_on_uninstall' => false,

		/*
		 * Let Grok sign in: Dynamic Client Registration, open only to callback
		 * addresses on Grok's hosts (OAuth\RegistrationPolicy). On, because
		 * the allow-list keeps it to Grok and every sign-in still needs an
		 * administrator's approval. Clients with a Client ID Metadata Document
		 * (Claude, ChatGPT) never need it.
		 */
		'oauth_grok_registration'  => true,
	);

	/**
	 * Cached values.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $values = null;

	/**
	 * The default value of every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Every setting, stored values over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
		}

		return $this->values;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Returned when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default_value = null ) {
		return $this->all()[ $key ] ?? $default_value;
	}

	/**
	 * Merge changes into the stored settings. Unknown keys are ignored.
	 *
	 * The merge base is read from the database, not from the cache: a cached
	 * copy describes the option as it was when this request read it.
	 *
	 * @param array<string, mixed> $changes Key => value.
	 * @return array<string, mixed> The stored settings after the write.
	 */
	public function update( array $changes ): array {
		$stored = get_option( self::OPTION, array() );
		$stored = array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );

		foreach ( $changes as $key => $value ) {
			/*
			 * A key this plugin does not define is dropped, not stored. The
			 * clamp that used to run after this check wrote its key whether or
			 * not the check had passed, which put a setting into the option
			 * that nothing here defines or reads.
			 */
			if ( array_key_exists( $key, self::DEFAULTS ) ) {
				$stored[ $key ] = self::sanitize( self::DEFAULTS[ $key ], $value );
			}
		}

		update_option( self::OPTION, $stored, true );
		$this->values = $stored;

		return $stored;
	}

	/**
	 * A boolean setting; unknown keys are false.
	 *
	 * @param string $key Setting name.
	 */
	public function bool( string $key ): bool {
		return (bool) $this->get( $key, false );
	}

	/**
	 * Forget cached values. For tests.
	 */
	public function flush(): void {
		$this->values = null;
	}

	/**
	 * Coerce a value to the type of its default.
	 *
	 * @param mixed $default_value The default, whose type wins.
	 * @param mixed $value         The incoming value.
	 * @return mixed
	 */
	private static function sanitize( $default_value, $value ) {
		if ( is_bool( $default_value ) ) {
			return (bool) rest_sanitize_boolean( $value );
		}

		if ( is_int( $default_value ) ) {
			return (int) $value;
		}

		return sanitize_text_field( (string) $value );
	}
}
