<?php
/**
 * The agents list, as in AI Agent OS PRO.
 *
 * This plugin has one agent, the Admin Assistant, but the tab opens on the
 * same list PRO shows: a header card, then a table with the agent's status,
 * connected apps, tools and customer data. Clicking its name opens the agent.
 * "Add agent" and "Export all agents" are PRO and say so.
 *
 * @package AiAgentOs
 *
 * @var \AiAgentOs\Agents\Agent $ai_agent_os_agent The site's agent.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/locked.php';
require_once __DIR__ . '/app-label.php';

/**
 * Permission repository.
 *
 * @var \AiAgentOs\Governance\PermissionRepository $ai_agent_os_perms
 */
$ai_agent_os_perms = ai_agent_os_service( \AiAgentOs\Governance\PermissionRepository::class );

/**
 * Customer data exposure.
 *
 * @var \AiAgentOs\Privacy\CustomerDataExposure $ai_agent_os_exposure
 */
$ai_agent_os_exposure = ai_agent_os_service( \AiAgentOs\Privacy\CustomerDataExposure::class );

$ai_agent_os_id    = $ai_agent_os_agent->get_id();
$ai_agent_os_name  = $ai_agent_os_agent->get_name();
$ai_agent_os_tools = (int) ( $ai_agent_os_perms->count_grants( $ai_agent_os_id )['total'] ?? 0 );
$ai_agent_os_data  = $ai_agent_os_exposure->describe( $ai_agent_os_id, $ai_agent_os_agent->masks_pii() );

// Every app signed in as the agent now, newest first, without repeats.
$ai_agent_os_apps = array();

foreach ( (array) ( ai_agent_os_service( \AiAgentOs\OAuth\OAuthStore::class )->signed_in_apps()[ $ai_agent_os_id ] ?? array() ) as $ai_agent_os_grant ) {
	$ai_agent_os_apps[] = ai_agent_os_app_label( (string) $ai_agent_os_grant['client_id'] );
}

$ai_agent_os_apps = array_values( array_unique( $ai_agent_os_apps ) );

// Connected, not connected, or why it cannot connect: the one thing this column needs to say.
if ( ! $ai_agent_os_agent->is_active() ) {
	$ai_agent_os_status = array(
		'key'   => 'revoked' === $ai_agent_os_agent->get_status() ? 'revoked' : 'expired',
		'label' => 'revoked' === $ai_agent_os_agent->get_status() ? __( 'Revoked', 'zorem-ai-agent-connector' ) : __( 'Not active', 'zorem-ai-agent-connector' ),
	);
} elseif ( array() !== $ai_agent_os_apps || null !== $ai_agent_os_agent->get_token_last_used_at() ) {
	$ai_agent_os_status = array(
		'key'   => 'connected',
		'label' => __( 'Connected', 'zorem-ai-agent-connector' ),
	);
} else {
	$ai_agent_os_status = array(
		'key'   => 'idle',
		'label' => __( 'Not connected', 'zorem-ai-agent-connector' ),
	);
}

$ai_agent_os_detail_url = add_query_arg(
	array(
		'page' => 'ai-agent-os',
		'tab'  => 'agent',
		'view' => 'agent',
	),
	admin_url( 'admin.php' )
);
?>
<div class="zui-layout zui-layout--full">
	<div class="zui-content zui-content--full">

		<div class="zui-section is-active">

			<div class="zui-card aaos-pagehead">
				<div class="aaos-pagehead__text">
					<h2 class="aaos-pagehead__title"><?php esc_html_e( 'Agents', 'zorem-ai-agent-connector' ); ?></h2>
					<p class="aaos-pagehead__sub">
						<?php esc_html_e( 'The AI agent that can connect to this store — its permissions, connected apps and access.', 'zorem-ai-agent-connector' ); ?>
					</p>
				</div>
				<?php // More agents are AI Agent OS PRO: the button shows the PRO badge and opens the upgrade page. ?>
				<a class="aaos-btn aaos-btn--primary aaos-pro-action" href="<?php echo esc_url( ai_agent_os_upgrade_url( 'add-agent' ) ); ?>" target="_blank" rel="noopener noreferrer"
					title="<?php esc_attr_e( 'More agents are in AI Agent OS PRO.', 'zorem-ai-agent-connector' ); ?>">
					<?php \Zorem\UI\icon( 'plus' ); ?>
					<span><?php esc_html_e( 'Add agent', 'zorem-ai-agent-connector' ); ?></span>
					<span class="zui-pro-feature__badge aaos-pro-action__badge"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?></span>
				</a>
			</div>

			<div class="aaos-tablecard">

				<div class="aaos-tablecard__toolbar">
					<p class="aaos-muted" style="margin:0">
						<?php esc_html_e( 'Click the agent’s name to set its permissions and connect an app.', 'zorem-ai-agent-connector' ); ?>
					</p>
					<a class="aaos-btn aaos-btn--ghost aaos-pro-action" href="<?php echo esc_url( ai_agent_os_upgrade_url( 'export-agents' ) ); ?>" target="_blank" rel="noopener noreferrer"
						title="<?php esc_attr_e( 'Exporting agents is in AI Agent OS PRO.', 'zorem-ai-agent-connector' ); ?>">
						<?php \Zorem\UI\icon( 'download' ); ?>
						<span><?php esc_html_e( 'Export all agents', 'zorem-ai-agent-connector' ); ?></span>
						<span class="zui-pro-feature__badge aaos-pro-action__badge"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?></span>
					</a>
				</div>

				<div class="aaos-tablecard__scroll">
					<table class="aaos-atable">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Acts for', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Status', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Connected apps', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Tools granted', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Customer data', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Expires', 'zorem-ai-agent-connector' ); ?></th>
								<th><?php esc_html_e( 'Last used', 'zorem-ai-agent-connector' ); ?></th>
								<th class="aaos-atable__actions"><?php esc_html_e( 'Actions', 'zorem-ai-agent-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td class="aaos-atable__name">
									<a href="<?php echo esc_url( $ai_agent_os_detail_url ); ?>"><?php echo esc_html( $ai_agent_os_name ); ?></a>
								</td>
								<td class="aaos-atable__muted"><?php echo esc_html( \AiAgentOs\Agents\Agent::acts_for_label( $ai_agent_os_agent->get_acts_for() ) ); ?></td>
								<td>
									<span class="aaos-pill aaos-pill--<?php echo esc_attr( $ai_agent_os_status['key'] ); ?>">
										<span class="aaos-pill__dot" aria-hidden="true"></span>
										<?php echo esc_html( $ai_agent_os_status['label'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( array() === $ai_agent_os_apps ) : ?>
										<span class="aaos-appchips__none">—</span>
									<?php else : ?>
										<span class="aaos-appchips">
											<?php foreach ( $ai_agent_os_apps as $ai_agent_os_app ) : ?>
												<span class="aaos-appchip aaos-appchip--<?php echo esc_attr( sanitize_html_class( strtolower( $ai_agent_os_app ) ) ); ?>">
													<span class="aaos-appchip__dot" aria-hidden="true"></span>
													<?php echo esc_html( $ai_agent_os_app ); ?>
												</span>
											<?php endforeach; ?>
										</span>
									<?php endif; ?>
								</td>
								<td class="aaos-atable__mono">
									<?php
									/* translators: %s: number of granted abilities. */
									echo esc_html( sprintf( _n( '%s tool', '%s tools', $ai_agent_os_tools, 'zorem-ai-agent-connector' ), number_format_i18n( $ai_agent_os_tools ) ) );
									?>
								</td>
								<td>
									<span class="aaos-tag aaos-tag--<?php echo esc_attr( $ai_agent_os_data['status'] ); ?>" title="<?php echo esc_attr( $ai_agent_os_data['detail'] ); ?>">
										<?php echo esc_html( $ai_agent_os_data['label'] ); ?>
									</span>
								</td>
								<td class="aaos-atable__muted"><?php echo esc_html( ai_agent_os_local_datetime( $ai_agent_os_agent->get_expires_at(), true ) ); ?></td>
								<td class="aaos-atable__mono aaos-atable__muted"><?php echo esc_html( ai_agent_os_local_datetime( $ai_agent_os_agent->get_token_last_used_at() ) ); ?></td>
								<td class="aaos-atable__actions">
									<a class="aaos-btn aaos-btn--ghost aaos-btn--sm" href="<?php echo esc_url( $ai_agent_os_detail_url ); ?>"><?php esc_html_e( 'Configure', 'zorem-ai-agent-connector' ); ?></a>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="aaos-tablecard__foot">
					<div>
						<?php
						printf(
							/* translators: 1: number shown, 2: number in total. */
							esc_html__( 'Showing %1$s of %2$s agent', 'zorem-ai-agent-connector' ),
							'<strong>1</strong>',
							'<strong>1</strong>'
						);
						?>
					</div>
				</div>
			</div>

		</div>
	</div>
</div>
