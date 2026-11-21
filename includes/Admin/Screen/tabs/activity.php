<?php
/**
 * The Activity Log tab: a locked preview of AI Agent OS PRO's log.
 *
 * The free plugin records nothing. The preview is PRO's own screen — the four
 * boxes and the feed grouped by day, with the same classes and styles — filled
 * with sample rows, so what is shown is what PRO looks like.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../partials/locked.php';

$ai_agent_os_tiles = array(
	array( 'all', 'clipboard-list', '24', __( 'Everything · last 7 days', 'zorem-ai-agent-connector' ) ),
	array( 'changed', 'edit', '6', __( 'Changes made', 'zorem-ai-agent-connector' ) ),
	array( 'problem', 'alert-circle', '1', __( 'Didn’t work', 'zorem-ai-agent-connector' ) ),
	array( 'blocked', 'lock', '3', __( 'Blocked', 'zorem-ai-agent-connector' ) ),
);

// Day, tone, icon, title, time, app, result, badge.
$ai_agent_os_samples = array(
	array( __( 'Today', 'zorem-ai-agent-connector' ), 'changed', 'edit', __( '“Change the Summer sale post back to draft”', 'zorem-ai-agent-connector' ), '10:42', 'Claude', __( '1 change', 'zorem-ai-agent-connector' ), __( 'Changed', 'zorem-ai-agent-connector' ) ),
	array( '', 'worked', 'check', __( '“Which pages mention shipping?”', 'zorem-ai-agent-connector' ), '10:35', 'ChatGPT', __( 'Looked at 12 items', 'zorem-ai-agent-connector' ), __( 'Looked only', 'zorem-ai-agent-connector' ) ),
	array( '', 'problem', 'alert-circle', __( '“Delete last week’s test posts and reply to new comments”', 'zorem-ai-agent-connector' ), '10:28', 'Claude', __( '1 change · 1 step blocked', 'zorem-ai-agent-connector' ), __( 'Partly done', 'zorem-ai-agent-connector' ) ),
	array( __( 'Yesterday', 'zorem-ai-agent-connector' ), 'blocked', 'lock', __( '3 failed connection attempts from Grok', 'zorem-ai-agent-connector' ), '18:02', '', '', __( 'Blocked', 'zorem-ai-agent-connector' ) ),
	array( '', 'changed', 'edit', __( '“Reply to the comment on Welcome”', 'zorem-ai-agent-connector' ), '16:20', 'Claude', __( '1 change', 'zorem-ai-agent-connector' ), __( 'Changed', 'zorem-ai-agent-connector' ) ),
);
?>
<div class="aaos-locked-block">
	<?php
	ai_agent_os_locked_card(
		array(
			'title'   => __( 'Unlock the Activity Log', 'zorem-ai-agent-connector' ),
			'desc'    => __( 'See every call your agents make and every one that was refused, with what was asked and what came back, filters, CSV export and how long entries are kept.', 'zorem-ai-agent-connector' ),
			'content' => 'activity-log',
		)
	);
	?>
	<div class="zui-lock-section__preview aaos-locked-preview aaos-alog" aria-hidden="true" inert>

		<div class="aaos-alog__tiles">
			<?php foreach ( $ai_agent_os_tiles as $ai_agent_os_i => $ai_agent_os_tile ) : ?>
				<span class="aaos-alog__tile aaos-alog__tile--<?php echo esc_attr( $ai_agent_os_tile[0] ); ?><?php echo esc_attr( 0 === $ai_agent_os_i ? ' is-active' : '' ); ?>">
					<span class="aaos-alog__tileicon"><?php \Zorem\UI\icon( $ai_agent_os_tile[1] ); ?></span>
					<span>
						<span class="aaos-alog__tilenum"><?php echo esc_html( $ai_agent_os_tile[2] ); ?></span>
						<span class="aaos-alog__tilelabel"><?php echo esc_html( $ai_agent_os_tile[3] ); ?></span>
					</span>
				</span>
			<?php endforeach; ?>
		</div>

		<div class="aaos-alog__card">
			<div class="aaos-alog__bar">
				<span class="aaos-alog__search">
					<span class="aaos-alog__searchicon"><?php \Zorem\UI\icon( 'search' ); ?></span>
					<span class="zui-input aaos-locked-preview__fake"><?php esc_html_e( 'Search what the AI was asked, an order number…', 'zorem-ai-agent-connector' ); ?></span>
				</span>
				<span class="zui-select aaos-locked-preview__fake"><?php esc_html_e( 'All agents', 'zorem-ai-agent-connector' ); ?></span>
				<span class="zui-select aaos-locked-preview__fake"><?php esc_html_e( 'Last 7 days', 'zorem-ai-agent-connector' ); ?></span>
			</div>

			<?php foreach ( $ai_agent_os_samples as $ai_agent_os_sample ) : ?>
				<?php if ( '' !== $ai_agent_os_sample[0] ) : ?>
					<p class="aaos-alog__day"><?php echo esc_html( $ai_agent_os_sample[0] ); ?></p>
				<?php endif; ?>
				<div class="aaos-alog__item">
					<div class="aaos-alog__row">
						<span class="aaos-alog__dot aaos-alog__dot--<?php echo esc_attr( $ai_agent_os_sample[1] ); ?>"><?php \Zorem\UI\icon( $ai_agent_os_sample[2] ); ?></span>
						<span class="aaos-alog__main">
							<span class="aaos-alog__rtitle"><?php echo esc_html( $ai_agent_os_sample[3] ); ?></span>
							<span class="aaos-alog__meta">
								<?php if ( '' !== $ai_agent_os_sample[5] ) : ?>
									<strong><?php esc_html_e( 'Admin Assistant', 'zorem-ai-agent-connector' ); ?></strong>
									<span class="aaos-alog__app"><?php echo esc_html( $ai_agent_os_sample[5] ); ?></span>
								<?php endif; ?>
								<span><?php echo esc_html( $ai_agent_os_sample[4] ); ?></span>
							</span>
						</span>
						<?php if ( '' !== $ai_agent_os_sample[6] ) : ?>
							<span class="aaos-alog__res"><?php echo esc_html( $ai_agent_os_sample[6] ); ?></span>
						<?php endif; ?>
						<span class="aaos-alog__pill aaos-alog__pill--<?php echo esc_attr( $ai_agent_os_sample[1] ); ?>"><?php echo esc_html( $ai_agent_os_sample[7] ); ?></span>
						<span class="aaos-alog__chev"><?php \Zorem\UI\icon( 'chevron-down' ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
