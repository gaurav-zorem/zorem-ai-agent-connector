<?php
/**
 * Settings, diagnostics and ability inventory endpoints.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Rest;

use AiAgentOs\Core\Requirements;
use AiAgentOs\Core\Settings;
use AiAgentOs\Governance\AbilityInventory;
use AiAgentOs\Mcp\Routes;
use AiAgentOs\Privacy\CoveredAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * The governance settings and what Diagnostics reports.
 *
 * Ported from the governance parts of the legacy settings controller. The
 * storefront, provider and knowledge settings belong to the plugins and the
 * kit that own them.
 */
final class SettingsController extends AbstractAdminController {

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		$permission = array( $this, 'check_permission' );

		register_rest_route(
			$this->namespace(),
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_site_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace(),
			'/diagnostics',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'diagnostics' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$this->namespace(),
			'/abilities',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'abilities' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * Read settings.
	 */
	public function get_settings(): \WP_REST_Response {
		/**
		 * Settings.
		 *
		 * @var Settings $settings
		 */
		$settings = $this->service( Settings::class );

		return rest_ensure_response(
			array(
				'settings' => $settings->all(),
				'defaults' => Settings::defaults(),
			)
		);
	}

	/**
	 * Write settings. Unknown keys are ignored; values are sanitised by type.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		/**
		 * Settings.
		 *
		 * @var Settings $settings
		 */
		$settings = $this->service( Settings::class );

		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : $request->get_body_params();

		return rest_ensure_response( array( 'settings' => $settings->update( (array) $params ) ) );
	}

	/**
	 * Everything the Diagnostics screen reports.
	 */
	public function diagnostics(): \WP_REST_Response {
		/**
		 * Requirements.
		 *
		 * @var Requirements $requirements
		 */
		$requirements = $this->service( Requirements::class );
		/**
		 * Covered abilities.
		 *
		 * @var CoveredAbilities $covered
		 */
		$covered = $this->service( CoveredAbilities::class );

		return rest_ensure_response(
			array_merge(
				$requirements->get_diagnostics(),
				array(
					'mcp_endpoint'    => trailingslashit( Routes::url() ) . \AiAgentOs\Mcp\AgentAddress::SEGMENT . '/…',
					'pii_abilities'   => $covered->abilities(),
					'pii_field_count' => count( $covered->fields() ),

					// What weakens governance and only a person can fix.
					'safety_warnings' => $this->service( \AiAgentOs\Core\SafetyChecks::class )->warnings(),
				)
			)
		);
	}

	/**
	 * The full ability inventory, grouped by category.
	 */
	public function abilities(): \WP_REST_Response {
		/**
		 * Ability inventory.
		 *
		 * @var AbilityInventory $inventory
		 */
		$inventory = $this->service( AbilityInventory::class );

		return rest_ensure_response(
			array(
				'categories' => $inventory->grouped_descriptors(),
				'total'      => count( $inventory->descriptors() ),
			)
		);
	}
}
