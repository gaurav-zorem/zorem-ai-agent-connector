<?php
/**
 * The name people know a signed-in app by: Claude, ChatGPT, Grok.
 *
 * Used by the agent header and the Connect page.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_app_label' ) ) {
	/**
	 * The name people know a signed-in app by, from its OAuth client id (an https address).
	 *
	 * @param string $client_id OAuth client id.
	 */
	function ai_agent_os_app_label( string $client_id ): string {
		// A client that registered itself: only Grok may (RegistrationPolicy). Its connector backend names itself "connectors-manager".
		$registered = ai_agent_os_service( \AiAgentOs\OAuth\OAuthStore::class )->find_client( $client_id );

		if ( null !== $registered ) {
			$name = strtolower( str_replace( array( '-', '_' ), ' ', $registered['client_name'] ) );

			return '' === $name || false !== strpos( $name, 'grok' ) || false !== strpos( $name, 'connectors manager' ) ? 'Grok' : $registered['client_name'];
		}

		$parts = wp_parse_url( $client_id );
		$hint  = strtolower( is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) . ' ' . (string) ( $parts['path'] ?? '' ) : $client_id );

		foreach ( array(
			'claude'  => 'Claude',
			'chatgpt' => 'ChatGPT',
			'openai'  => 'ChatGPT',
			'cursor'  => 'Cursor',
			'grok'    => 'Grok',
		) as $needle => $label ) {
			if ( false !== strpos( $hint, $needle ) ) {
				return $label;
			}
		}

		return is_array( $parts ) && ! empty( $parts['host'] ) ? (string) $parts['host'] : __( 'An app', 'zorem-ai-agent-connector' );
	}
}
