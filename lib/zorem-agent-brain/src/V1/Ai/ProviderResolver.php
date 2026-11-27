<?php
/**
 * Which AI provider the site uses, and where its key comes from.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the site's AI provider and the source of its key, in order of authority.
 *
 * 1. **wp-config.php or the environment** — `ANTHROPIC_API_KEY` and friends.
 *    The safest place for a secret, and the administrator's explicit choice.
 * 2. **WordPress's own Connectors screen** (WordPress 7). Core already hands
 *    those keys to its AI client on `init`; the Brain only has to notice.
 * 3. **The Website Brain's stored key** (CredentialStore), which ModelGateway
 *    hands to the AI client when a lease is granted.
 *
 * A key on its own is not enough: WordPress's AI client ships with no
 * provider implementations, and each provider comes from its own plugin
 * (`ai-provider-for-anthropic`, …). `client_ready` says whether that plugin is
 * there, so the screen can say "install the provider" rather than "add a key"
 * to someone who already has.
 */
final class ProviderResolver {

	/** Providers and the constants or environment variables that hold their keys. */
	private const KNOWN = array(
		'anthropic' => array( 'ANTHROPIC_API_KEY' ),
		'openai'    => array( 'OPENAI_API_KEY' ),
		'google'    => array( 'GOOGLE_API_KEY', 'GEMINI_API_KEY' ),
	);

	/** Display names. */
	private const LABELS = array(
		// The company and the model people know it by: "Google" alone did not say Gemini.
		'anthropic' => 'Anthropic (Claude)',
		'openai'    => 'OpenAI (ChatGPT)',
		'google'    => 'Google (Gemini)',
	);

	public const SOURCE_CONSTANT    = 'constant';
	public const SOURCE_ENVIRONMENT = 'environment';
	public const SOURCE_CONNECTORS  = 'connectors';
	public const SOURCE_BRAIN       = 'brain';
	public const SOURCE_NONE        = 'none';

	/**
	 * The stored key.
	 *
	 * @var CredentialStore
	 */
	private CredentialStore $credentials;

	/**
	 * Constructor.
	 *
	 * @param CredentialStore $credentials The stored key.
	 */
	public function __construct( CredentialStore $credentials ) {
		$this->credentials = $credentials;
	}

	/**
	 * Known provider ids.
	 *
	 * @return array<int, string>
	 */
	public static function known_providers(): array {
		return array_keys( self::KNOWN );
	}

	/**
	 * A provider's display name.
	 *
	 * @param string $provider Provider id.
	 */
	public static function label( string $provider ): string {
		return self::LABELS[ $provider ] ?? $provider;
	}

	/**
	 * The constants that can hold a provider's key.
	 *
	 * @param string $provider Provider id.
	 * @return array<int, string>
	 */
	public static function constants_for( string $provider ): array {
		return self::KNOWN[ $provider ] ?? array();
	}

	/**
	 * The provider in use and where its key comes from.
	 *
	 * @return array{provider: string, source: string, credential: string, configured: bool, salt_rotated: bool, client_ready: bool, model: string}
	 */
	public function resolve(): array {
		/**
		 * Filters the preferred AI provider, '' for the first one configured.
		 *
		 * @param string $provider Provider id.
		 */
		$preferred = (string) apply_filters( 'zorem_agent_brain_ai_provider', '' );
		$order     = '' !== $preferred && isset( self::KNOWN[ $preferred ] ) ? array( $preferred ) : self::known_providers();

		foreach ( $order as $provider ) {
			foreach ( self::KNOWN[ $provider ] as $name ) {
				if ( defined( $name ) && '' !== (string) constant( $name ) ) {
					return $this->describe( $provider, self::SOURCE_CONSTANT, 'constant ' . $name );
				}

				$value = getenv( $name );

				if ( is_string( $value ) && '' !== $value ) {
					return $this->describe( $provider, self::SOURCE_ENVIRONMENT, 'environment variable ' . $name );
				}
			}
		}

		foreach ( $order as $provider ) {
			if ( $this->has_connector_key( $provider ) ) {
				return $this->describe( $provider, self::SOURCE_CONNECTORS, 'WordPress Connectors' );
			}
		}

		$stored = $this->credentials->describe();

		if ( $stored['stored'] && in_array( $stored['provider'], $order, true ) ) {
			$described = $this->describe( $stored['provider'], self::SOURCE_BRAIN, 'stored in the Website Brain' );

			if ( $stored['unreadable'] ) {
				$described['configured']   = false;
				$described['salt_rotated'] = true;
			}

			return $described;
		}

		return $this->describe( '' !== $preferred ? $preferred : '', self::SOURCE_NONE, 'not configured' );
	}

	/**
	 * Whether WordPress's Connectors screen (or its env/constant) holds a key for a provider.
	 *
	 * @param string $provider Provider id.
	 */
	private function has_connector_key( string $provider ): bool {
		if ( ! function_exists( 'wp_get_connector' ) ) {
			return false;
		}

		$auth = (array) ( wp_get_connector( $provider )['authentication'] ?? array() );

		if ( 'api_key' !== ( $auth['method'] ?? '' ) || empty( $auth['setting_name'] ) ) {
			return false;
		}

		foreach ( array( (string) ( $auth['env_var_name'] ?? '' ) ) as $env ) {
			if ( '' !== $env && is_string( getenv( $env ) ) && '' !== getenv( $env ) ) {
				return true;
			}
		}

		$constant = (string) ( $auth['constant_name'] ?? '' );

		if ( '' !== $constant && defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return true;
		}

		$option = get_option( (string) $auth['setting_name'], '' );

		return is_string( $option ) && '' !== $option;
	}

	/**
	 * Whether WordPress's AI client has an implementation for a provider.
	 *
	 * @param string $provider Provider id.
	 */
	public static function client_ready( string $provider ): bool {
		$ready = false;

		if ( '' !== $provider && class_exists( '\WordPress\AiClient\AiClient' ) ) {
			try {
				$ready = \WordPress\AiClient\AiClient::defaultRegistry()->hasProvider( $provider );
			} catch ( \Throwable $e ) {
				$ready = false;
			}
		}

		/**
		 * Filters whether the AI client can serve a provider.
		 *
		 * @param bool   $ready    Whether a provider implementation is registered.
		 * @param string $provider Provider id.
		 */
		return (bool) apply_filters( 'zorem_agent_brain_ai_client_ready', $ready, $provider );
	}

	/**
	 * A resolution record.
	 *
	 * @param string $provider   Provider id.
	 * @param string $source     One of the SOURCE_* constants.
	 * @param string $credential Human description of the source.
	 * @return array{provider: string, source: string, credential: string, configured: bool, salt_rotated: bool, client_ready: bool, model: string}
	 */
	private function describe( string $provider, string $source, string $credential ): array {
		return array(
			'provider'     => $provider,
			'source'       => $source,
			'credential'   => $credential,
			'configured'   => self::SOURCE_NONE !== $source,
			'salt_rotated' => false,
			'client_ready' => self::client_ready( $provider ),
			// The model chosen on the AI provider tab for this provider, '' for its default (ModelChoice).
			'model'        => '' !== $provider ? ModelChoice::for_provider( $provider ) : '',
		);
	}
}
