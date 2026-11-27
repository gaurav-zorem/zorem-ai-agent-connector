<?php
/**
 * Which AI model the site uses, chosen on the AI provider tab.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * The administrator's model, for the provider it was chosen for.
 *
 * Empty means "the provider's default", which is what WordPress's AI client
 * picks on its own. A choice matters when that default is not available to the
 * key: a free-tier Google key, for example, has no quota for some models and
 * every request to them fails with "quota exceeded".
 *
 * The choice belongs to one provider. Switching to another provider falls back
 * to that provider's default rather than sending, say, a Gemini model name to
 * Anthropic.
 *
 * `zorem_agent_brain_ai_model` still has the last word, so a site can pin a
 * model in code.
 */
final class ModelChoice {

	/** Option: provider and model. */
	public const OPTION = 'zorem_agent_brain_ai_model';

	/** Transient prefix for a provider's model list. */
	private const CACHE = 'zorem_agent_brain_ai_models_';

	/** How long a model list is kept. */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/** What a model id may contain (provider ids such as "gemini-2.5-flash" or "models/x"). */
	private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,119}$/';

	/**
	 * The model for a provider, '' for its default. Filtered.
	 *
	 * @param string $provider Provider id.
	 */
	public static function for_provider( string $provider ): string {
		$stored = (array) get_option( self::OPTION, array() );
		$model  = ( $stored['provider'] ?? '' ) === $provider ? (string) ( $stored['model'] ?? '' ) : '';

		/**
		 * Filters the AI model the Website Brain asks for; '' lets the provider choose.
		 *
		 * @param string $model    The administrator's choice, or ''.
		 * @param string $provider Provider id.
		 */
		return (string) apply_filters( 'zorem_agent_brain_ai_model', $model, $provider );
	}

	/**
	 * Remember a model for a provider; '' goes back to the provider's default.
	 *
	 * @param string $provider Provider id.
	 * @param string $model    Model id, or ''.
	 * @return true|\WP_Error
	 */
	public static function save( string $provider, string $model ) {
		$model = trim( $model );

		if ( ! in_array( $provider, ProviderResolver::known_providers(), true ) ) {
			return new \WP_Error( 'zorem_agent_brain_model_provider', __( 'Unknown AI provider.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		if ( '' !== $model && 1 !== preg_match( self::ID_PATTERN, $model ) ) {
			return new \WP_Error( 'zorem_agent_brain_model_invalid', __( 'That is not a model name.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		update_option(
			self::OPTION,
			array(
				'provider' => $provider,
				'model'    => $model,
			),
			false
		);

		return true;
	}

	/**
	 * The provider's models that can write text and call tools, as id => name.
	 *
	 * Asked from the provider with the site's key (so the list is what that key
	 * can see) and kept for 12 hours. Empty when the provider cannot be reached;
	 * the screen then offers only the default.
	 *
	 * @param string $provider Provider id.
	 * @param bool   $fresh    Ask again instead of using the kept list.
	 * @return array<string, string>
	 */
	public static function models( string $provider, bool $fresh = false ): array {
		if ( '' === $provider ) {
			return array();
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE . $provider );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$models = array();

		if ( class_exists( '\WordPress\AiClient\AiClient' ) && class_exists( '\WordPress\AiClient\Providers\Models\DTO\ModelRequirements' ) ) {
			try {
				$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
					array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
					array( new \WordPress\AiClient\Providers\Models\DTO\RequiredOption( \WordPress\AiClient\Providers\Models\Enums\OptionEnum::functionDeclarations(), true ) )
				);

				foreach ( \WordPress\AiClient\AiClient::defaultRegistry()->findProviderModelsMetadataForSupport( $provider, $requirements ) as $metadata ) {
					$models[ (string) $metadata->getId() ] = (string) $metadata->getName();
				}
			} catch ( \Throwable $e ) {
				$models = array();
			}
		}

		// An empty answer is kept briefly, so a provider that is down is asked again soon.
		set_transient( self::CACHE . $provider, $models, array() === $models ? 5 * MINUTE_IN_SECONDS : self::CACHE_TTL );

		return $models;
	}

	/**
	 * Forget every kept model list.
	 */
	public static function flush(): void {
		foreach ( ProviderResolver::known_providers() as $provider ) {
			delete_transient( self::CACHE . $provider );
		}
	}
}
