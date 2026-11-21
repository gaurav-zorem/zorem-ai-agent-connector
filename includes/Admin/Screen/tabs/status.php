<?php
/**
 * The Status tab, with Diagnostics.
 *
 * Read top to bottom: a header with Copy diagnostics, four tiles that answer
 * "is it running and is anything wrong", what needs attention (only when
 * something does), then the details grouped by what they are about. Each fact
 * is shown once. Every value is computed above the markup, so no expression
 * sits inside an attribute (CS-SEC-3).
 *
 * @package AiAgentOs
 *
 * @var \AiAgentOs\Core\Requirements $ai_agent_os_requirements Checked requirements.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../section-map.php';
require_once __DIR__ . '/../partials/services.php';

$ai_agent_os_warnings = \AiAgentOs\Plugin::instance()->container()->get( \AiAgentOs\Core\SafetyChecks::class )->warnings();

/*
 * The admin routes need the nonce as well as the capability, internal
 * dispatch included; without it this read returns 403 and the panel would
 * say it could not look.
 */
$ai_agent_os_diag_request = new WP_REST_Request( 'GET', '/' . \AiAgentOs\Mcp\Routes::ADMIN_NAMESPACE . '/diagnostics' );
$ai_agent_os_diag_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$ai_agent_os_diag_response = rest_do_request( $ai_agent_os_diag_request );
$ai_agent_os_diagnostics   = $ai_agent_os_diag_response->is_error() ? array() : (array) $ai_agent_os_diag_response->get_data();

// Everything that needs a look, counted once for the Health tile.
$ai_agent_os_problems = count( $ai_agent_os_warnings )
	+ ( empty( $ai_agent_os_diagnostics ) ? 1 : 0 )
	+ ( ! empty( $ai_agent_os_diagnostics['salt_rotated'] ) ? 1 : 0 )
	+ ( array() !== (array) ( $ai_agent_os_diagnostics['schema']['missing_tables'] ?? array() ) ? 1 : 0 )
	+ ( array() !== \ZoremAgentBridge\V1\Bridge::registry()->conflicts() ? 1 : 0 );

/*
 * The agent, as a tile. The two facts worth seeing from here are whether it
 * can act at all and what it is called — the rest of the story is on its own
 * tab, which is where someone goes next.
 */
$ai_agent_os_agent = ai_agent_os_service( \AiAgentOs\Agents\AgentRepository::class )->get();

if ( ! $ai_agent_os_agent instanceof \AiAgentOs\Agents\Agent ) {
	$ai_agent_os_agent_tile = array(
		'value'  => __( 'Not set up', 'zorem-ai-agent-connector' ),
		'note'   => __( 'Set up when an administrator opens the admin', 'zorem-ai-agent-connector' ),
		'accent' => 'info',
	);
} elseif ( $ai_agent_os_agent->is_active() ) {
	$ai_agent_os_agent_tile = array(
		'value'  => __( 'Active', 'zorem-ai-agent-connector' ),
		'note'   => $ai_agent_os_agent->get_name(),
		'accent' => 'success',
	);
} else {
	$ai_agent_os_states = array(
		'agent_revoked'   => __( 'Revoked', 'zorem-ai-agent-connector' ),
		'agent_expired'   => __( 'Expired', 'zorem-ai-agent-connector' ),
		'agent_suspended' => __( 'Suspended', 'zorem-ai-agent-connector' ),
	);

	$ai_agent_os_agent_tile = array(
		'value'  => $ai_agent_os_states[ (string) $ai_agent_os_agent->inactive_reason() ] ?? __( 'Inactive', 'zorem-ai-agent-connector' ),
		'note'   => $ai_agent_os_agent->get_name(),
		'accent' => 'warning',
	);
}

// Switched off under Settings, or unreachable because the MCP Adapter is missing.
$ai_agent_os_mcp_on = ai_agent_os_service( \AiAgentOs\Core\Settings::class )->bool( 'mcp_enabled' )
	&& $ai_agent_os_requirements->has_mcp_adapter();

$ai_agent_os_tiles = array(
	array(
		'label'  => AI_AGENT_OS_NAME,
		'value'  => AI_AGENT_OS_VERSION,
		'note'   => __( 'Running', 'zorem-ai-agent-connector' ),
		'accent' => 'success',
	),
	array(
		'label'  => __( 'Agent', 'zorem-ai-agent-connector' ),
		'value'  => $ai_agent_os_agent_tile['value'],
		'note'   => $ai_agent_os_agent_tile['note'],
		'accent' => $ai_agent_os_agent_tile['accent'],
	),
	array(
		'label'  => __( 'MCP endpoint', 'zorem-ai-agent-connector' ),
		'value'  => $ai_agent_os_mcp_on ? __( 'On', 'zorem-ai-agent-connector' ) : __( 'Off', 'zorem-ai-agent-connector' ),
		'note'   => $ai_agent_os_mcp_on
			? __( 'Reachable by an AI app', 'zorem-ai-agent-connector' )
			: __( 'Closed — no app can connect', 'zorem-ai-agent-connector' ),
		'accent' => $ai_agent_os_mcp_on ? 'success' : 'warning',
	),
	array(
		'label'  => __( 'Website Brain', 'zorem-ai-agent-connector' ),
		'value'  => __( 'PRO', 'zorem-ai-agent-connector' ),
		'note'   => __( 'In AI Agent OS PRO', 'zorem-ai-agent-connector' ),
		'accent' => 'info',
	),
	array(
		'label'  => __( 'Health', 'zorem-ai-agent-connector' ),
		'value'  => 0 === $ai_agent_os_problems
			? __( 'All good', 'zorem-ai-agent-connector' )
			/* translators: %s: number of things to check. */
			: sprintf( _n( '%s to check', '%s to check', $ai_agent_os_problems, 'zorem-ai-agent-connector' ), number_format_i18n( $ai_agent_os_problems ) ),
		'note'   => 0 === $ai_agent_os_problems ? __( 'Nothing needs attention', 'zorem-ai-agent-connector' ) : __( 'Marked below', 'zorem-ai-agent-connector' ),
		'accent' => 0 === $ai_agent_os_problems ? 'success' : 'warning',
	),
);
?>
<div class="aaos-body aaos-status">

	<div class="zui-section-header">
		<div class="zui-section-header__main">
			<span class="zui-section-header__icon" aria-hidden="true"><?php \Zorem\UI\icon( 'shield-check' ); ?></span>
			<div class="zui-section-header__text">
				<h2 class="zui-section-header__title"><?php esc_html_e( 'Status', 'zorem-ai-agent-connector' ); ?></h2>
				<p class="zui-section-header__sub"><?php esc_html_e( 'What is running on this site, and anything that needs a look. Copy diagnostics into a support ticket.', 'zorem-ai-agent-connector' ); ?></p>
			</div>
		</div>
		<div class="zui-section-header__actions">
			<?php
			/*
			 * The payload is written into the page rather than re-fetched, so
			 * what a merchant pastes into a support ticket is exactly what they
			 * were looking at when they clicked — not a second read that may differ.
			 */
			?>
			<button type="button" class="aaos-btn aaos-btn--ghost" data-aaos-copy-diagnostics>
				<?php \Zorem\UI\icon( 'clipboard-list' ); ?>
				<span><?php esc_html_e( 'Copy diagnostics', 'zorem-ai-agent-connector' ); ?></span>
			</button>
		</div>
	</div>

	<?php if ( ! empty( $ai_agent_os_diagnostics['salt_rotated'] ) ) : ?>
		<div class="zui-notice zui-notice--error">
			<?php esc_html_e( "This site's security salts changed. The agent's token is keyed to those salts, so it has stopped working. The agent and its permissions are intact — issue it a new token to restore access.", 'zorem-ai-agent-connector' ); ?>
		</div>
	<?php endif; ?>

	<div class="aaos-status__tiles">
		<?php foreach ( $ai_agent_os_tiles as $ai_agent_os_tile ) : ?>
			<div class="zui-card zui-stat">
				<span class="zui-stat__accent zui-stat__accent--<?php echo esc_attr( $ai_agent_os_tile['accent'] ); ?>"></span>
				<div class="zui-stat__label"><?php echo esc_html( $ai_agent_os_tile['label'] ); ?></div>
				<div class="zui-stat__value"><?php echo esc_html( $ai_agent_os_tile['value'] ); ?></div>
				<div class="zui-stat__delta"><?php echo esc_html( $ai_agent_os_tile['note'] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( array() !== $ai_agent_os_warnings ) : ?>
		<section class="zui-card aaos-status__attention">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php \Zorem\UI\icon( 'alert-circle' ); ?><span><?php esc_html_e( 'Needs attention', 'zorem-ai-agent-connector' ); ?></span></h3>
			</div>
			<div class="zui-card__body">
				<ul class="aaos-warnings">
					<?php foreach ( $ai_agent_os_warnings as $ai_agent_os_warning ) : ?>
						<li><?php echo esc_html( $ai_agent_os_warning ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		</section>
	<?php endif; ?>

	<?php require __DIR__ . '/../partials/diagnostics.php'; ?>

</div>
