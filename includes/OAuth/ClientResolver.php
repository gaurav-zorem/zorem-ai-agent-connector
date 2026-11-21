<?php
/**
 * Client identification.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a `client_id` into what the client claims about itself.
 *
 * Two sources (plan D8): a URL-shaped id is a metadata document fetched live;
 * a `zcl_` id is a client that registered itself, which only Grok may do
 * (RegistrationPolicy). Neither is authentication (plan §3.2) — anyone can
 * host a JSON file. What they establish is where a
 * code may be sent, and that is enforced here by exact match (S7).
 */
final class ClientResolver {

	/**
	 * Longest client name shown anywhere.
	 */
	public const NAME_MAX = 60;

	/**
	 * Metadata document retrieval.
	 *
	 * @var CimdFetcher
	 */
	private CimdFetcher $cimd;

	/**
	 * Registered clients.
	 *
	 * @var OAuthStore
	 */
	private OAuthStore $store;

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param CimdFetcher $cimd     Metadata document retrieval.
	 * @param OAuthStore  $store    Registered clients.
	 * @param Settings    $settings Plugin settings.
	 */
	public function __construct( CimdFetcher $cimd, OAuthStore $store, Settings $settings ) {
		$this->cimd     = $cimd;
		$this->store    = $store;
		$this->settings = $settings;
	}

	/**
	 * Resolve a client.
	 *
	 * @param string $client_id Client id.
	 * @return array{client_id: string, name: string, redirect_uris: array<int, string>, kind: string, auth_method: string, secret_hash: string|null}|\WP_Error
	 */
	public function resolve( string $client_id ) {
		if ( '' === $client_id || strlen( $client_id ) > 255 ) {
			return new \WP_Error( 'ai_agent_os_oauth_invalid_client', __( 'The client did not identify itself.', 'zorem-ai-agent-connector' ) );
		}

		if ( $this->cimd->is_document_url( $client_id ) ) {
			$document = $this->cimd->fetch( $client_id );

			if ( is_wp_error( $document ) ) {
				return $document;
			}

			return array(
				'client_id'     => $client_id,
				'name'          => self::clean_name( (string) ( $document['client_name'] ?? '' ) ),
				'redirect_uris' => $this->valid_redirects( (array) $document['redirect_uris'] ),
				'kind'          => 'cimd',
				'auth_method'   => 'none',
				'secret_hash'   => null,
			);
		}

		/*
		 * Switching registration off switches off the clients that registered
		 * themselves, not only new registrations: otherwise the setting reads
		 * as a way to stop them and does nothing about the ones already there.
		 */
		$client = ( new RegistrationPolicy( $this->settings ) )->is_enabled() ? $this->store->find_client( $client_id ) : null;

		if ( null !== $client ) {
			return array(
				'client_id'     => $client['client_id'],
				'name'          => self::clean_name( $client['client_name'] ),
				// Re-checked against the allow-list on every use, so narrowing it applies to clients already registered.
				'redirect_uris' => array_values( array_filter( $this->valid_redirects( $client['redirect_uris'] ), array( RegistrationPolicy::class, 'allows_redirect' ) ) ),
				'kind'          => 'dcr',
				'auth_method'   => $client['auth_method'],
				'secret_hash'   => $client['secret_hash'],
			);
		}

		return new \WP_Error( 'ai_agent_os_oauth_invalid_client', __( 'This site does not know that client. Apps sign in with a client metadata document (an https address), Grok by registering; others connect with the agent\'s token.', 'zorem-ai-agent-connector' ) );
	}

	/**
	 * Whether a redirect URI is one this flow may ever send a code to (S12).
	 *
	 * HTTPS anywhere, or plain http to the loopback interface only, for clients
	 * running on the user's own machine. No fragment, no other scheme.
	 *
	 * @param string $uri Redirect URI.
	 */
	public static function is_allowed_redirect( string $uri ): bool {
		if ( '' === $uri || strlen( $uri ) > 2000 ) {
			return false;
		}

		$parts = wp_parse_url( $uri );

		if ( ! is_array( $parts ) || isset( $parts['fragment'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );

		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme && self::is_loopback_host( (string) $parts['host'] );
	}

	/**
	 * Whether a host is the loopback interface (S9's warning case).
	 *
	 * @param string $host Host.
	 */
	public static function is_loopback_host( string $host ): bool {
		return in_array( strtolower( trim( $host, '[]' ) ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * Make a client-supplied name safe to put in front of a human.
	 *
	 * Plan §3.3.2: the name is hostile input. Control characters, line breaks
	 * and bidirectional overrides are stripped so it cannot break out of its
	 * container or reverse the reading order of the sentence around it, and
	 * it is length-capped. Escaping happens at output, as always.
	 *
	 * @param string $name Raw name.
	 */
	public static function clean_name( string $name ): string {
		$name = (string) preg_replace( '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', ' ', $name );
		$name = trim( (string) preg_replace( '/\s+/u', ' ', $name ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $name ) > self::NAME_MAX ) {
			$name = rtrim( mb_substr( $name, 0, self::NAME_MAX ) ) . '…';
		}

		return $name;
	}

	/**
	 * The redirect URIs from a client's claim that this flow would honour.
	 *
	 * @param array<int, mixed> $uris Claimed URIs.
	 * @return array<int, string>
	 */
	private function valid_redirects( array $uris ): array {
		$valid = array();

		foreach ( $uris as $uri ) {
			if ( is_string( $uri ) && self::is_allowed_redirect( $uri ) ) {
				$valid[] = $uri;
			}
		}

		return $valid;
	}
}
