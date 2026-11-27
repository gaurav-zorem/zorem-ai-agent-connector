<?php
/**
 * The site's AI provider key, when it is stored in the database.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ai;

use ZoremAgentBrain\V1\Security\SecretBox;

defined( 'ABSPATH' ) || exit;

/**
 * One AI key per site (decision D-4), sealed with SecretBox.
 *
 * Ported from the legacy CredentialStore with two changes: one key for the
 * site rather than one per provider — both plugins use the same provider and
 * the same key — and authenticated encryption in place of AES-CBC without a
 * MAC.
 *
 * A key is stored only after the administrator has acknowledged what storing
 * it here does and does not protect against (ConsentLedger). Once stored it is
 * never shown again: `describe()` gives the provider, the last four characters
 * and whether it can still be decrypted.
 */
final class CredentialStore {

	public const OPTION  = 'zorem_agent_brain_ai_credential';
	public const CONTEXT = 'ai-key';

	/**
	 * The acknowledgement ledger.
	 *
	 * @var ConsentLedger
	 */
	private ConsentLedger $consent;

	/**
	 * Constructor.
	 *
	 * @param ConsentLedger $consent The acknowledgement ledger.
	 */
	public function __construct( ConsentLedger $consent ) {
		$this->consent = $consent;
	}

	/**
	 * Store the site's key.
	 *
	 * @param string $provider One of ProviderResolver::known_providers().
	 * @param string $key      The key.
	 * @param int    $user_id  Who stored it.
	 * @return true|\WP_Error
	 */
	public function store( string $provider, string $key, int $user_id ) {
		$provider = sanitize_key( $provider );
		$key      = trim( $key );

		if ( ! in_array( $provider, ProviderResolver::known_providers(), true ) ) {
			return new \WP_Error( 'zorem_agent_brain_ai_unknown_provider', __( 'Unknown AI provider.', 'zorem-ai-agent-connector' ) );
		}

		if ( '' === $key || strlen( $key ) > 512 || 1 !== preg_match( '/^[\x21-\x7E]+$/', $key ) ) {
			return new \WP_Error( 'zorem_agent_brain_ai_invalid_key', __( 'That does not look like an API key.', 'zorem-ai-agent-connector' ) );
		}

		if ( ! $this->consent->recorded() ) {
			return new \WP_Error( 'zorem_agent_brain_ai_consent_required', __( 'Storing a key in the database needs your acknowledgement of what that protection does and does not cover.', 'zorem-ai-agent-connector' ) );
		}

		$sealed = SecretBox::seal( $key, self::CONTEXT );

		if ( null === $sealed ) {
			return new \WP_Error( 'zorem_agent_brain_ai_store_failed', __( 'The key could not be stored securely on this server.', 'zorem-ai-agent-connector' ) );
		}

		update_option(
			self::OPTION,
			array(
				'provider' => $provider,
				'sealed'   => $sealed,
				'last4'    => substr( $key, -4 ),
				'fp'       => SecretBox::fingerprint( self::CONTEXT ),
				'by'       => $user_id,
				'at'       => current_time( 'mysql', true ),
			),
			false
		);

		return true;
	}

	/**
	 * The stored key, or null when none is stored or it cannot be decrypted.
	 */
	public function get(): ?string {
		$stored = $this->raw();

		return '' === (string) ( $stored['sealed'] ?? '' ) ? null : SecretBox::open( (string) $stored['sealed'], self::CONTEXT );
	}

	/**
	 * The provider the stored key belongs to, '' for none.
	 */
	public function provider(): string {
		return (string) ( $this->raw()['provider'] ?? '' );
	}

	/**
	 * What a screen may show. Never the key.
	 *
	 * @return array{stored: bool, provider: string, last4: string, unreadable: bool}
	 */
	public function describe(): array {
		$stored = $this->raw();
		$has    = '' !== (string) ( $stored['sealed'] ?? '' );

		return array(
			'stored'     => $has,
			'provider'   => $has ? (string) ( $stored['provider'] ?? '' ) : '',
			'last4'      => $has ? (string) ( $stored['last4'] ?? '' ) : '',
			'unreadable' => $has && null === $this->get(),
		);
	}

	/**
	 * Delete the stored key.
	 */
	public function forget(): bool {
		return delete_option( self::OPTION );
	}

	/**
	 * The stored record.
	 *
	 * @return array<string, mixed>
	 */
	private function raw(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}
}
