<?php
/**
 * The Settings tab: general and diagnostics.
 *
 * The AI provider is not here: which model a site may call is decided through
 * the bridge's model access, by the plugins that call one.
 *
 * ## Where the data comes from
 *
 * Through `rest_do_request()` against the plugin's own routes, not by calling
 * the services directly. The route is the contract the React screen used and
 * the one the save round-trip still uses, so reading it here means the
 * server-rendered first paint cannot drift from what a refresh would fetch.
 * It costs an internal dispatch and buys the guarantee that there is exactly
 * one shape of this data.
 *
 * The permission callback runs on that internal request too, so this is not a
 * way around the capability check.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../partials/services.php';
require_once __DIR__ . '/../renderer.php';
require_once __DIR__ . '/../section-map.php';

/**
 * Dispatch one of our own admin routes internally.
 *
 * @param string $route Route path after the namespace.
 * @return array<string, mixed>
 */
$ai_agent_os_fetch = static function ( string $route ): array {
	$request = new WP_REST_Request( 'GET', '/' . \AiAgentOs\Mcp\Routes::ADMIN_NAMESPACE . $route );

	/*
	 * The admin routes require `X-WP-Nonce` as well as the capability — that
	 * seam is the whole point of `check_permission()`, and an internal dispatch
	 * is not exempt from it. Without this header these reads return 403 and the
	 * screen paints defaults with no error anywhere, which is exactly the kind
	 * of silent-wrong this project keeps refusing to ship.
	 *
	 * The value is the same nonce the page hands the browser, minted for the
	 * same already-capability-checked user in the same request. It is not a way
	 * around the check; the capability half still runs.
	 */
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array();
	}

	return (array) $response->get_data();
};

$ai_agent_os_settings = (array) ( $ai_agent_os_fetch( '/settings' )['settings'] ?? array() );

// One section, so no sidebar. Diagnostics moved to the Status tab, beside the versions it explains.
?>
<div class="zui-layout zui-layout--full">

	<div class="zui-content zui-content--full">

		<?php
		// ---------------------------------------------------------------- General.
		ai_agent_os_section_open( 'general', true );
		?>
		<form class="aaos-settings-form" data-aaos-settings-form>
			<?php
			ai_agent_os_section_header(
				__( 'General', 'zorem-ai-agent-connector' ),
				__( 'How the endpoint behaves, and what is kept.', 'zorem-ai-agent-connector' ),
				true
			);
			?>

			<div class="zui-card">
				<?php
				ai_agent_os_render_fields(
					array(
						'mcp_enabled'              => array(
							'type'  => 'toggle',
							'title' => __( 'Enable the MCP endpoint', 'zorem-ai-agent-connector' ),
							'desc'  => __( 'Turning this off closes the endpoint entirely. Your agent and its permissions are kept.', 'zorem-ai-agent-connector' ),
							'value' => ! empty( $ai_agent_os_settings['mcp_enabled'] ),
						),
						'delete_data_on_uninstall' => array(
							'type'  => 'toggle',
							'title' => __( 'Delete all data when uninstalling', 'zorem-ai-agent-connector' ),
							'desc'  => __( 'Off by default. When off, deleting the plugin leaves your agent and its permissions in place.', 'zorem-ai-agent-connector' ),
							'value' => ! empty( $ai_agent_os_settings['delete_data_on_uninstall'] ),
						),
					)
				);
				?>
			</div>
		</form>

		<?php ai_agent_os_section_close(); ?>

	</div>
</div>
