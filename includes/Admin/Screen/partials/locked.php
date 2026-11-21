<?php
/**
 * Locked previews of AI Agent OS PRO features.
 *
 * The free plugin shows what PRO adds, the way Advanced Shipment Tracking
 * does: a lock card with an "Upgrade to PRO" button above a dimmed, disabled
 * sample of the PRO screen. Every preview is static markup only — none of
 * PRO's code is in this plugin, so nothing here can be switched on.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_upgrade_url' ) ) {
	/**
	 * The upgrade link, tagged with where on the screen it was clicked.
	 *
	 * @param string $content Where the link sits, e.g. "activity-log".
	 */
	function ai_agent_os_upgrade_url( string $content ): string {
		return add_query_arg(
			array(
				'utm_source'   => 'wp-admin',
				'utm_medium'   => 'zorem-ai-agent-connector',
				'utm_campaign' => 'upgrade-to-pro',
				'utm_content'  => sanitize_key( $content ),
			),
			'https://www.zorem.com/product/ai-agent-os/'
		);
	}
}

if ( ! function_exists( 'ai_agent_os_pro_badge' ) ) {
	/**
	 * The PRO pill and lock chip.
	 */
	function ai_agent_os_pro_badge(): void {
		?>
		<span class="zui-pro-feature">
			<span class="zui-pro-feature__badge"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?></span>
			<span class="zui-pro-feature__lock" aria-hidden="true"><?php \Zorem\UI\icon( 'lock' ); ?></span>
		</span>
		<?php
	}
}

if ( ! function_exists( 'ai_agent_os_locked_card' ) ) {
	/**
	 * The lock card: icon, "Unlock …" with a PRO badge, a short text and the upgrade button.
	 *
	 * @param array{title: string, desc: string, content: string} $args Title, text, and where it sits (for the link).
	 */
	function ai_agent_os_locked_card( array $args ): void {
		?>
		<div class="zui-lock-section aaos-locked">
			<div class="zui-lock-section__icon"><?php \Zorem\UI\icon( 'lock' ); ?></div>
			<h3 class="zui-lock-section__title">
				<?php echo esc_html( $args['title'] ); ?>
				<span class="zui-lock-section__badge"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?></span>
			</h3>
			<p class="zui-lock-section__desc"><?php echo esc_html( $args['desc'] ); ?></p>
			<a class="zui-btn-primary zui-lock-section__cta" href="<?php echo esc_url( ai_agent_os_upgrade_url( $args['content'] ) ); ?>" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'Upgrade to PRO', 'zorem-ai-agent-connector' ); ?>
			</a>
		</div>
		<?php
	}
}

if ( ! function_exists( 'ai_agent_os_locked_row' ) ) {
	/**
	 * One disabled settings row with the PRO badge, for a dimmed preview.
	 *
	 * @param string $title Row title.
	 * @param string $desc  Row description.
	 * @param string $type  `toggle` or `number`.
	 */
	function ai_agent_os_locked_row( string $title, string $desc, string $type = 'toggle' ): void {
		?>
		<div class="zui-row zui-row--inline aaos-locked-row">
			<div class="zui-row__head">
				<span class="zui-row__label"><?php echo esc_html( $title ); ?></span>
				<p class="zui-row__desc"><?php echo esc_html( $desc ); ?></p>
			</div>
			<div class="zui-row__control aaos-locked-row__control">
				<?php ai_agent_os_pro_badge(); ?>
				<?php if ( 'number' === $type ) : ?>
					<input type="number" class="zui-input" value="30" disabled aria-disabled="true">
				<?php else : ?>
					<label class="zui-toggle" aria-disabled="true">
						<input type="checkbox" class="zui-toggle__input" disabled>
						<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
					</label>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
