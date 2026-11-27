<?php
/**
 * May this request use the site's AI provider, and if so, ready it.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ai;

use ZoremAgentBridge\V1\Contracts\ModelAccessInterface;
use ZoremAgentBridge\V1\Dto\ModelLease;

defined( 'ABSPATH' ) || exit;

/**
 * The bridge's model-access provider: one place that decides "may we" and
 * installs the key, for every AI use on the site.
 *
 * Ported from the legacy ProviderGateway. Both plugins ask through the bridge
 * (`Bridge::model()->lease()`): Experiences' assistants with purpose
 * "storefront", "admin-chat" or "support", the Brain's enrichment with
 * "enrichment". A lease is granted only when, in order:
 *
 * 1. WordPress's AI client exists and AI is not switched off for the site.
 * 2. A key is configured (ProviderResolver), and a stored one can still be
 *    decrypted.
 * 3. The provider's implementation is installed in the AI client.
 * 4. A key stored in the Brain is covered, for this purpose, by the
 *    administrator's acknowledgement (ConsentLedger).
 *
 * A granted lease for a stored key installs it into the AI client for this
 * request. **The legacy plugin announced the key through an action nothing
 * listened to**, so a key saved in its settings was never used; here the key
 * is handed to the client directly, and a test asserts it arrives.
 *
 * Messages come in two voices. An administrator is told the actual cause and
 * what to do; a shopper is told only that the shop has not finished setting up
 * its assistant — never which provider, never that a key is missing.
 */
final class ModelGateway implements ModelAccessInterface {

	/**
	 * Provider resolution.
	 *
	 * @var ProviderResolver
	 */
	private ProviderResolver $resolver;

	/**
	 * The stored key.
	 *
	 * @var CredentialStore
	 */
	private CredentialStore $credentials;

	/**
	 * The acknowledgement ledger.
	 *
	 * @var ConsentLedger
	 */
	private ConsentLedger $consent;

	/**
	 * Constructor.
	 *
	 * @param ProviderResolver $resolver    Provider resolution.
	 * @param CredentialStore  $credentials The stored key.
	 * @param ConsentLedger    $consent     The acknowledgement ledger.
	 */
	public function __construct( ProviderResolver $resolver, CredentialStore $credentials, ConsentLedger $consent ) {
		$this->resolver    = $resolver;
		$this->credentials = $credentials;
		$this->consent     = $consent;
	}

	/**
	 * Ask to use the site's AI provider.
	 *
	 * @param string $purpose  e.g. "enrichment", "storefront", "admin-chat", "support".
	 * @param string $audience ModelAccessInterface::AUDIENCE_ADMIN or AUDIENCE_SHOPPER.
	 */
	public function lease( string $purpose, string $audience ): ModelLease {
		if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
			return $this->refuse( ModelLease::UNAVAILABLE, '', __( 'AI is switched off on this site, or this WordPress version has no AI client.', 'zorem-ai-agent-connector' ) );
		}

		$resolved = $this->resolver->resolve();
		$provider = $resolved['provider'];
		$label    = ProviderResolver::label( $provider );

		if ( $resolved['salt_rotated'] ) {
			return $this->refuse( ModelLease::SALT_ROTATED, $provider, __( 'This site\'s security keys changed, so the stored AI key can no longer be decrypted. Enter it again.', 'zorem-ai-agent-connector' ) );
		}

		if ( ! $resolved['configured'] ) {
			return $this->refuse( ModelLease::NOT_CONFIGURED, $provider, __( 'No AI provider is configured. Add a key in WordPress\'s Connectors screen or wp-config.php, or store one in the AI provider settings.', 'zorem-ai-agent-connector' ) );
		}

		if ( ! $resolved['client_ready'] ) {
			return $this->refuse(
				ModelLease::UNAVAILABLE,
				$provider,
				/* translators: %s: provider name, e.g. Anthropic. */
				sprintf( __( 'A key for %1$s is set, but its provider plugin is not installed, so WordPress cannot reach it. Install the AI provider plugin for %2$s.', 'zorem-ai-agent-connector' ), $label, $label )
			);
		}

		if ( ProviderResolver::SOURCE_BRAIN === $resolved['source'] ) {
			/*
			 * A shopper-facing call needs the assistants' acknowledgement whatever
			 * purpose the caller names. The audience used to be ignored, so
			 * `lease( 'enrichment', AUDIENCE_SHOPPER )` passed on an
			 * enrichment-only acknowledgement and sent shoppers' questions out
			 * under consent that never covered them.
			 */
			if ( ! $this->consent->covers( self::AUDIENCE_SHOPPER === $audience ? 'shopper' : $purpose ) ) {
				return $this->refuse( ModelLease::CONSENT_STALE, $provider, __( 'The stored AI key has not been acknowledged for this use. Open the AI provider settings and confirm the acknowledgement.', 'zorem-ai-agent-connector' ) );
			}

			if ( ! $this->install_stored_key( $provider ) ) {
				return $this->refuse( ModelLease::UNAVAILABLE, $provider, __( 'The stored AI key could not be handed to WordPress\'s AI client.', 'zorem-ai-agent-connector' ) );
			}
		}

		return new ModelLease( ModelLease::OK, $provider );
	}

	/**
	 * Generate text on behalf of the Brain (enrichment). Admin audience.
	 *
	 * @param string               $system  System instruction.
	 * @param string               $message User message.
	 * @param string               $purpose Lease purpose.
	 * @param array<string, mixed> $context Passed to the short-circuit filter.
	 * @return string|\WP_Error
	 */
	public function generate_text( string $system, string $message, string $purpose, array $context = array() ) {
		$lease = $this->lease( $purpose, self::AUDIENCE_ADMIN );

		if ( ! $lease->is_ok() ) {
			return new \WP_Error( 'zorem_agent_brain_ai_' . $lease->state(), $lease->admin_message(), array( 'state' => $lease->state() ) );
		}

		/**
		 * Short-circuits a model call — for tests and for sites routing calls elsewhere.
		 *
		 * Runs only after a lease was granted, so it cannot bypass "may we".
		 *
		 * @param string|\WP_Error|null $result  Null to call the provider.
		 * @param string                $system  System instruction.
		 * @param string                $message User message.
		 * @param array<string, mixed>  $context Purpose, provider and caller context.
		 */
		$short = apply_filters(
			'zorem_agent_brain_ai_call',
			null,
			$system,
			$message,
			array_merge(
				$context,
				array(
					'purpose'  => $purpose,
					'provider' => $lease->provider(),
				)
			)
		);

		if ( is_string( $short ) || is_wp_error( $short ) ) {
			return $short;
		}

		$builder = wp_ai_client_prompt( $message )->using_system_instruction( $system )->using_provider( $lease->provider() );
		$model   = ModelChoice::for_provider( $lease->provider() );

		// The builder forwards using_* through __call, so method_exists() cannot see it.
		if ( '' !== $model ) {
			$builder = $builder->using_model_preference( $model );
		}

		$text = $builder->generate_text();

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$text = trim( (string) $text );

		return '' === $text ? new \WP_Error( 'zorem_agent_brain_ai_empty', __( 'The AI provider returned nothing.', 'zorem-ai-agent-connector' ) ) : $text;
	}

	/**
	 * The availability summary for a settings screen.
	 *
	 * @param string $purpose Purpose to check.
	 * @return array<string, mixed>
	 */
	public function availability( string $purpose = 'enrichment' ): array {
		$lease = $this->lease( $purpose, self::AUDIENCE_ADMIN );

		return array_merge(
			$this->resolver->resolve(),
			array(
				'state'         => $lease->state(),
				'available'     => $lease->is_ok(),
				'admin_message' => $lease->admin_message(),
				'consent'       => $this->consent->describe(),
				'stored_key'    => $this->credentials->describe(),
			)
		);
	}

	/**
	 * Hand the stored key to WordPress's AI client for this request.
	 *
	 * @param string $provider Provider id.
	 */
	private function install_stored_key( string $provider ): bool {
		$key = $this->credentials->get();

		if ( null === $key || ! class_exists( '\WordPress\AiClient\AiClient' ) || ! class_exists( '\WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication' ) ) {
			return false;
		}

		try {
			\WordPress\AiClient\AiClient::defaultRegistry()->setProviderRequestAuthentication(
				$provider,
				new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $key )
			);
		} catch ( \Throwable $e ) {
			return false;
		}

		return true;
	}

	/**
	 * A refusal, in both voices.
	 *
	 * @param string $state    ModelLease state.
	 * @param string $provider Provider id.
	 * @param string $admin    What to tell an administrator.
	 */
	private function refuse( string $state, string $provider, string $admin ): ModelLease {
		/**
		 * Filters what a shopper is told when the assistant cannot run.
		 *
		 * @param string $message The sentence.
		 * @param string $state   Why, for the site's own logic; never shown as is.
		 */
		$shopper = (string) apply_filters( 'zorem_agent_brain_shopper_unconfigured_message', __( 'The shop has not finished setting up its assistant.', 'zorem-ai-agent-connector' ), $state );

		return new ModelLease( $state, $provider, $admin, $shopper );
	}
}
