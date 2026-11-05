<?php
/**
 * The Agent tab: this site's agent, its permissions and its connection.
 *
 * The site has one agent, the Admin Assistant, provisioned on the first admin
 * page after activation (SiteAgent). There is no list and no chooser: the tab
 * opens on the agent itself.
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

$ai_agent_os_agent = $ai_agent_os_repository->current();

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

$ai_agent_os_agent_id = $ai_agent_os_agent->get_id();

require __DIR__ . '/../partials/agent-detail.php';
