<?php
/**
 * The Website Brain tab: a locked preview of AI Agent OS PRO's Website Brain.
 *
 * The Website Brain is not in the free plugin. The preview shows PRO's
 * Overview as it looks — what the agents know, the store policies it reads and
 * the pages you add — with sample numbers and pages.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../partials/locked.php';

$ai_agent_os_stats = array(
	array( '49', __( 'Items agents can answer from', 'zorem-ai-agent-connector' ) ),
	array( '8', __( 'Plugins read', 'zorem-ai-agent-connector' ) ),
	array( '3', __( 'Your pages', 'zorem-ai-agent-connector' ) ),
	array( '2', __( 'Bundled documentation', 'zorem-ai-agent-connector' ) ),
	array( '1', __( 'Store settings', 'zorem-ai-agent-connector' ) ),
	array( '65,766', __( 'Approximate size, in tokens', 'zorem-ai-agent-connector' ) ),
);

$ai_agent_os_policies = array(
	array( __( 'Terms & conditions', 'zorem-ai-agent-connector' ), __( 'Terms & Conditions', 'zorem-ai-agent-connector' ) ),
	array( __( 'Refunds & returns', 'zorem-ai-agent-connector' ), __( 'Returns & Refunds', 'zorem-ai-agent-connector' ) ),
	array( __( 'Privacy policy', 'zorem-ai-agent-connector' ), __( 'Privacy Policy', 'zorem-ai-agent-connector' ) ),
);

// Page title, ticked.
$ai_agent_os_pages = array(
	array( __( 'About Us', 'zorem-ai-agent-connector' ), true ),
	array( __( 'Cart', 'zorem-ai-agent-connector' ), false ),
	array( __( 'FAQ', 'zorem-ai-agent-connector' ), true ),
	array( __( 'Contact', 'zorem-ai-agent-connector' ), false ),
	array( __( 'Shipping & Delivery', 'zorem-ai-agent-connector' ), true ),
	array( __( 'Shop', 'zorem-ai-agent-connector' ), false ),
);
?>
<div class="aaos-locked-block">
	<?php
	ai_agent_os_locked_card(
		array(
			'title'   => __( 'Unlock the Website Brain', 'zorem-ai-agent-connector' ),
			'desc'    => __( 'Give your agents what your site knows — policies, chosen pages, store settings and installed plugins — and decide which agent may read which part.', 'zorem-ai-agent-connector' ),
			'content' => 'website-brain',
		)
	);
	?>
	<div class="zui-lock-section__preview aaos-locked-preview aaos-lbrain" aria-hidden="true" inert>

		<div class="zui-card aaos-lbrain__card">
			<div class="aaos-lbrain__head">
				<div>
					<h3 class="aaos-lbrain__title"><?php esc_html_e( 'What your agents know', 'zorem-ai-agent-connector' ); ?></h3>
					<p class="aaos-lbrain__lead"><?php esc_html_e( 'Your agents can answer from 49 items of written knowledge, read from your store.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<span class="aaos-btn aaos-btn--ghost aaos-btn--sm"><?php \Zorem\UI\icon( 'refresh-cw' ); ?><span><?php esc_html_e( 'Read everything again', 'zorem-ai-agent-connector' ); ?></span></span>
			</div>
			<div class="aaos-lbrain__stats">
				<?php foreach ( $ai_agent_os_stats as $ai_agent_os_i => $ai_agent_os_stat ) : ?>
					<span class="aaos-lbrain__stat<?php echo esc_attr( 0 === $ai_agent_os_i ? ' is-active' : '' ); ?>">
						<b><?php echo esc_html( $ai_agent_os_stat[0] ); ?></b>
						<span><?php echo esc_html( $ai_agent_os_stat[1] ); ?></span>
					</span>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="aaos-lbrain__cols">
			<div class="zui-card aaos-lbrain__card">
				<h3 class="aaos-lbrain__title"><?php esc_html_e( 'Store policies', 'zorem-ai-agent-connector' ); ?></h3>
				<p class="aaos-lbrain__lead"><?php esc_html_e( 'What an agent answers from when a shopper asks about returns, delivery or payment.', 'zorem-ai-agent-connector' ); ?></p>
				<div class="aaos-lbrain__rows">
					<?php foreach ( $ai_agent_os_policies as $ai_agent_os_policy ) : ?>
						<div class="aaos-lbrain__row">
							<span><?php echo esc_html( $ai_agent_os_policy[0] ); ?> <span class="aaos-lbrain__read"><?php esc_html_e( 'Read', 'zorem-ai-agent-connector' ); ?></span></span>
							<span class="zui-select aaos-locked-preview__fake"><?php echo esc_html( $ai_agent_os_policy[1] ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="zui-card aaos-lbrain__card">
				<h3 class="aaos-lbrain__title"><?php esc_html_e( 'Add another page', 'zorem-ai-agent-connector' ); ?></h3>
				<p class="aaos-lbrain__lead"><?php esc_html_e( 'An FAQ, a shipping guide, a size chart — any page can become knowledge. Nothing is read until you tick it.', 'zorem-ai-agent-connector' ); ?></p>
				<div class="aaos-lbrain__pages">
					<?php foreach ( $ai_agent_os_pages as $ai_agent_os_page ) : ?>
						<span class="aaos-lbrain__page">
							<span class="aaos-lbrain__box<?php echo esc_attr( $ai_agent_os_page[1] ? ' is-on' : '' ); ?>"></span>
							<?php echo esc_html( $ai_agent_os_page[0] ); ?>
							<span class="aaos-lbrain__kind"><?php esc_html_e( 'page', 'zorem-ai-agent-connector' ); ?></span>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
	</div>
</div>
