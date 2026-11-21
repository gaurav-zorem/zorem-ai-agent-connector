<?php
/**
 * The Agent tab: this site's agent, its permissions and its connection.
 *
 * The agent is the Admin Assistant, set up on the first admin page after
 * activation (SiteAgent). The tab opens on the agent itself.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../partials/services.php';
require_once __DIR__ . '/../partials/modal.php';
require_once __DIR__ . '/../renderer.php';
require_once __DIR__ . '/../section-map.php';

/**
 * Agent repository.
 *
 * @var \AiAgentOs\Agents\AgentRepository $ai_agent_os_repository
 */
$ai_agent_os_repository = ai_agent_os_service( \AiAgentOs\Agents\AgentRepository::class );

$ai_agent_os_agent = $ai_agent_os_repository->get();

if ( ! $ai_agent_os_agent instanceof \AiAgentOs\Agents\Agent ) :
	?>
	<div class="zui-layout zui-layout--full">
		<div class="zui-content zui-content--full">
			<div class="zui-card">
				<div class="aaos-empty">
					<p class="aaos-empty__text"><?php esc_html_e( 'Your Admin Assistant is being set up.', 'zorem-ai-agent-connector' ); ?></p>
					<p class="aaos-empty__hint"><?php esc_html_e( 'Reload this page to see it. It is set up the first time an administrator opens the WordPress admin after activating the plugin.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>
		</div>
	</div>
	<?php
	return;
endif;

/*
 * The tab opens on the agents list, as in AI Agent OS PRO; the agent's own
 * page is `view=agent`, and any `section=` link (Connect, Access & safety…)
 * lands there too, so links sent from elsewhere keep working.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Read-only view state.
$ai_agent_os_open_agent = ( isset( $_GET['view'] ) && 'agent' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) || isset( $_GET['section'] );

if ( ! $ai_agent_os_open_agent ) {
	require __DIR__ . '/../partials/agent-list.php';
	return;
}

require __DIR__ . '/../partials/agent-detail.php';
