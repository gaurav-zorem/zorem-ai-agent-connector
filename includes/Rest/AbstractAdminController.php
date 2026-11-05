<?php
/**
 * Base class for admin REST controllers.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Rest;

use AiAgentOs\Core\Capabilities;
use AiAgentOs\Core\Container;
use AiAgentOs\Mcp\Routes;

defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing and the security baseline for every admin endpoint.
 *
 * Both checks apply to every route, including reads. The capability check is
 * the authorisation; the nonce is the CSRF defence. A GET that leaks the full
 * agent roster is worth protecting as carefully as a POST that changes one.
 */
abstract class AbstractAdminController {

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * The REST namespace for admin endpoints.
	 */
	protected function namespace(): string {
		return Routes::ADMIN_NAMESPACE;
	}

	/**
	 * Permission callback for every admin route.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function check_permission( \WP_REST_Request $request ) {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			return new \WP_Error(
				'ai_agent_os_forbidden',
				__( 'You do not have permission to manage the agent.', 'zorem-ai-agent-connector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$nonce = $request->get_header( 'x_wp_nonce' );

		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'ai_agent_os_invalid_nonce',
				__( 'Your session has expired. Reload the page and try again.', 'zorem-ai-agent-connector' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Permission callback for site-wide settings and risk ratings.
	 *
	 * These change what every agent may do, so they need an administrator
	 * (`manage_options`) as well as `manage_agent_os`, which can be handed to
	 * a Shop Manager.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function check_site_permission( \WP_REST_Request $request ) {
		$checked = $this->check_permission( $request );

		if ( true !== $checked ) {
			return $checked;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'ai_agent_os_forbidden',
				__( 'Only an administrator can change site-wide settings.', 'zorem-ai-agent-connector' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 */
	protected function service( string $id ) {
		return $this->container->get( $id );
	}

	/**
	 * A standard error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status.
	 */
	protected function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
