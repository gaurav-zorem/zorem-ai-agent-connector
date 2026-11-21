<?php
/**
 * Manage agent: revoke or reactivate the agent.
 *
 * Kept apart from everyday settings so the action that stops every app is
 * never one row away from a toggle someone came to flip.
 *
 * @package AiAgentOs
 *
 * @var \AiAgentOs\Agents\Agent $ai_agent_os_agent The agent.
 */

defined( 'ABSPATH' ) || exit;

ai_agent_os_section_header(
	__( 'Manage agent', 'zorem-ai-agent-connector' ),
	__( 'Switch this agent off, or back on. Nothing is deleted.', 'zorem-ai-agent-connector' )
);
?>
<div class="zui-card">
	<div class="zui-card__head">
		<h3 class="zui-card__title"><?php esc_html_e( 'Status', 'zorem-ai-agent-connector' ); ?></h3>
	</div>
	<div class="zui-row zui-row--inline">
		<div class="zui-row__head">
			<span class="zui-row__label">
				<?php
				/*
				 * Expiry is part of this answer, not a separate fact.
				 *
				 * This reported `revoked` or `active` and nothing else, so an
				 * expired agent was described as active here while the header
				 * badge two inches above said Expired. Two sources of truth on
				 * one screen — and the one a merchant is about to act on was
				 * the wrong one.
				 */
				if ( 'revoked' === $ai_agent_os_agent->get_status() ) {
					echo esc_html__( 'This agent is revoked', 'zorem-ai-agent-connector' );
				} elseif ( $ai_agent_os_agent->is_expired() ) {
					echo esc_html__( 'This agent has expired', 'zorem-ai-agent-connector' );
				} else {
					echo esc_html__( 'This agent is active', 'zorem-ai-agent-connector' );
				}
				?>
			</span>
			<p class="zui-row__desc">
				<?php esc_html_e( 'Revoking stops the token working. Nothing is deleted — its permissions stay, and you can reactivate it.', 'zorem-ai-agent-connector' ); ?>
			</p>
		</div>
		<div class="zui-row__control">
			<?php if ( 'revoked' === $ai_agent_os_agent->get_status() ) : ?>
				<button type="button" class="zui-btn zui-btn-secondary" data-aaos-reactivate>
					<?php esc_html_e( 'Reactivate this agent', 'zorem-ai-agent-connector' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="zui-btn zui-btn-danger"
					data-zui-modal-toggle="aaos-agent-revoke"
					data-aaos-agent-name="<?php echo esc_attr( $ai_agent_os_agent->get_name() ); ?>">
					<?php esc_html_e( 'Revoke this agent', 'zorem-ai-agent-connector' ); ?>
				</button>
			<?php endif; ?>
		</div>
	</div>

</div>
