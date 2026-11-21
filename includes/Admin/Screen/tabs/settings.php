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
require_once __DIR__ . '/../partials/locked.php';

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
				__( 'Whether AI apps can connect, and what is kept.', 'zorem-ai-agent-connector' ),
				true
			);
			?>

			<div class="zui-card aaos-aligned">
				<?php
				ai_agent_os_render_fields(
					array(
						'mcp_enabled'              => array(
							'type'  => 'toggle',
							'title'   => __( 'Allow AI apps to connect', 'zorem-ai-agent-connector' ),
							'desc'    => __( 'Off blocks every agent address. Agents and permissions are kept.', 'zorem-ai-agent-connector' ),
							'tooltip' => __( 'This is the MCP endpoint every agent address uses. Turn it off to stop all AI apps at once.', 'zorem-ai-agent-connector' ),
							'value' => ! empty( $ai_agent_os_settings['mcp_enabled'] ),
						),
						'oauth_grok_registration'  => array(
							'type'  => 'toggle',
							'title'   => __( 'Let Grok sign in', 'zorem-ai-agent-connector' ),
							'desc'    => __( 'Allow Grok (grok.com) connectors to sign in.', 'zorem-ai-agent-connector' ),
							'tooltip' => __( 'Grok has to register with this site first. Only Grok’s own addresses (grok.com, x.ai) may register, and you still approve every connection. Turning it off disconnects Grok.', 'zorem-ai-agent-connector' ),
							'value' => ! empty( $ai_agent_os_settings['oauth_grok_registration'] ),
						),
						'delete_data_on_uninstall' => array(
							'type'  => 'toggle',
							'title'   => __( 'Delete all data when uninstalling', 'zorem-ai-agent-connector' ),
							'desc'    => __( 'Off keeps your agent and its permissions if the plugin is removed.', 'zorem-ai-agent-connector' ),
							'value' => ! empty( $ai_agent_os_settings['delete_data_on_uninstall'] ),
						),
					)
				);
				?>
			</div>
		</form>

		<?php // The Activity Log's settings, as locked PRO rows. ?>
		<div class="zui-card aaos-locked-settings">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php esc_html_e( 'Activity Log', 'zorem-ai-agent-connector' ); ?></h3>
				<?php ai_agent_os_pro_badge(); ?>
			</div>
			<div class="zui-lock-section__preview">
				<?php
				ai_agent_os_locked_row( __( 'Keep activity for (days)', 'zorem-ai-agent-connector' ), __( 'How long each call your agents make is kept, from 7 to 365 days.', 'zorem-ai-agent-connector' ), 'number' );
				ai_agent_os_locked_row( __( 'Keep the full request and reply', 'zorem-ai-agent-connector' ), __( 'Open any entry later to see what the AI asked for and what was sent back.', 'zorem-ai-agent-connector' ) );
				?>
			</div>
			<p class="aaos-locked-settings__cta">
				<a class="zui-btn zui-btn-secondary" href="<?php echo esc_url( ai_agent_os_upgrade_url( 'settings' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Upgrade to PRO', 'zorem-ai-agent-connector' ); ?></a>
			</p>
		</div>

		<?php ai_agent_os_section_close(); ?>

	</div>
</div>
