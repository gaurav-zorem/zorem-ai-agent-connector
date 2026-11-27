<?php
/**
 * Base for the Brain's admin REST controllers.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Rest;

use ZoremAgentBrain\V1\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Shared permission check and helpers for `zorem-agent-brain/v1`.
 *
 * Every route — reads included — needs the `manage_agent_os` capability that
 * both plugins grant to administrators. With cookie authentication WordPress
 * only treats a request as logged in when it carries the `wp_rest` nonce, so a
 * cross-site page cannot read the Brain through an administrator's browser.
 */
abstract class Controller {

	public const NAMESPACE = 'zorem-agent-brain/v1';

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Whether the current user may manage the Website Brain.
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		if ( current_user_can( Brain::CAPABILITY ) ) {
			return true;
		}

		return new \WP_Error(
			'zorem_agent_brain_forbidden',
			__( 'You do not have permission to manage the Website Brain.', 'zorem-ai-agent-connector' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Whether the current user may change the Website Brain.
	 *
	 * The Brain's capability can be handed to someone who is not an
	 * administrator (a Shop Manager who manages agents). Reading stays open to
	 * them; every change — the AI provider key and data-sharing consent, the
	 * site-wide settings, which sources are read, and the scope rules that
	 * decide what each agent may see — is a site-wide decision and needs an
	 * administrator as well.
	 *
	 * @return true|\WP_Error
	 */
	public function check_change_permission() {
		$allowed = $this->check_permission();

		if ( true !== $allowed ) {
			return $allowed;
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'zorem_agent_brain_forbidden',
			__( 'Only a site administrator can change the Website Brain.', 'zorem-ai-agent-connector' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Register one route with the shared permission check.
	 *
	 * Reads need the Brain's capability; anything else also needs an
	 * administrator (see `check_change_permission()`).
	 *
	 * @param string               $route    Route, relative to the namespace.
	 * @param string               $methods  HTTP methods.
	 * @param callable             $callback Handler.
	 * @param array<string, mixed> $args     Argument schema.
	 */
	protected function route( string $route, string $methods, callable $callback, array $args = array() ): void {
		register_rest_route(
			self::NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => $callback,
				'permission_callback' => \WP_REST_Server::READABLE === $methods ? array( $this, 'check_permission' ) : array( $this, 'check_change_permission' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * A WP_Error with an HTTP status, or the response.
	 *
	 * @param mixed $result Handler result.
	 * @param int   $status Status for a WP_Error that has none.
	 * @return \WP_REST_Response|\WP_Error
	 */
	protected function respond( $result, int $status = 400 ) {
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();

			if ( ! is_array( $data ) || ! isset( $data['status'] ) ) {
				$result->add_data( array_merge( is_array( $data ) ? $data : array(), array( 'status' => $status ) ) );
			}

			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * A required string argument schema.
	 *
	 * @param int $max Maximum length.
	 * @return array<string, mixed>
	 */
	protected static function required_string( int $max = 191 ): array {
		return array(
			'type'              => 'string',
			'required'          => true,
			'maxLength'         => $max,
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * An optional string argument schema.
	 *
	 * @param int $max Maximum length.
	 * @return array<string, mixed>
	 */
	protected static function optional_string( int $max = 191 ): array {
		return array(
			'type'              => 'string',
			'default'           => '',
			'maxLength'         => $max,
			'sanitize_callback' => 'sanitize_text_field',
		);
	}
}
