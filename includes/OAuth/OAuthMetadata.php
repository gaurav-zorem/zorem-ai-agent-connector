<?php
/**
 * The two discovery documents.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

use AiAgentOs\Core\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the protected-resource and authorization-server metadata.
 *
 * These are a protocol handshake, not an about page. Nothing here names the
 * site, describes the agent, lists profiles or states a version — the plan's §3.1
 * accepts that a stranger learns this site serves an MCP address, and nothing more.
 *
 * `scopes_supported` is absent from both on purpose (plan S17 / D7): a refusal
 * here is terminal, and publishing a scope vocabulary would invite a client to
 * treat one as the start of a negotiation.
 */
final class OAuthMetadata {

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * RFC 9728 Protected Resource Metadata (S1).
	 *
	 * For the base MCP path, or for the agent's MCP address. Any well-formed code gets
	 * its document — it only repeats the address back — so asking reveals
	 * nothing about the agent.
	 *
	 * @param string $code The agent's address code, or '' for the base path.
	 * @return array<string, mixed>
	 */
	public function protected_resource( string $code = '' ): array {
		return array(
			'resource'                 => OAuthEndpoints::resource( $code ),
			'authorization_servers'    => array( OAuthEndpoints::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * RFC 8414 Authorization Server Metadata (S2–S5).
	 *
	 * `client_secret_basic` is not offered: WordPress core reads any Basic
	 * Authorization header as an application password and refuses the request
	 * before it reaches the token endpoint.
	 *
	 * `registration_endpoint` is present only while "Let Grok sign in" is on
	 * (RegistrationPolicy): Grok's connectors can only sign in by registering.
	 * Everyone else identifies with a metadata document
	 * (`client_id_metadata_document_supported`). Its absence is how a client
	 * is specified to learn DCR is unavailable, so leaving it off conforms.
	 *
	 * @return array<string, mixed>
	 */
	public function authorization_server(): array {
		$metadata = array(
			'issuer'                                => OAuthEndpoints::issuer(),
			'authorization_endpoint'                => OAuthEndpoints::url( 'authorize' ),
			'token_endpoint'                        => OAuthEndpoints::url( 'token' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'client_id_metadata_document_supported' => true,
			'authorization_response_iss_parameter_supported' => true,
		);

		if ( ( new RegistrationPolicy( $this->settings ) )->is_enabled() ) {
			$metadata['registration_endpoint']                 = OAuthEndpoints::url( 'register' );
			$metadata['token_endpoint_auth_methods_supported'] = array( 'none', 'client_secret_post' );
		}

		return $metadata;
	}
}
