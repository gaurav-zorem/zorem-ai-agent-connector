<?php
/**
 * The Go PRO tab: what AI Agent OS PRO adds.
 *
 * Laid out like Advanced Shipment Tracking's Go Pro tab: a gradient hero with
 * the plan status, a strip of four facts, six PRO features, a free-vs-PRO
 * comparison, a before/after pair and a closing call to action.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../partials/locked.php';

$ai_agent_os_upgrade = ai_agent_os_upgrade_url( 'go-pro' );

$ai_agent_os_stats = array(
	array(
		'value'  => __( 'Unlimited', 'zorem-ai-agent-connector' ),
		'label'  => __( 'Agents', 'zorem-ai-agent-connector' ),
		'sub'    => __( 'One per AI app, team or task', 'zorem-ai-agent-connector' ),
		'icon'   => 'layers',
		'tint'   => '#eff6ff',
		'accent' => '#2563eb',
	),
	array(
		'value'  => __( '15', 'zorem-ai-agent-connector' ),
		'label'  => __( 'Site-building tools', 'zorem-ai-agent-connector' ),
		'sub'    => __( 'Homepage, logo, menus, templates, colours and fonts', 'zorem-ai-agent-connector' ),
		'icon'   => 'zap',
		'tint'   => '#f5f3ff',
		'accent' => '#7c3aed',
	),
	array(
		'value'  => __( '365 days', 'zorem-ai-agent-connector' ),
		'label'  => __( 'Activity history', 'zorem-ai-agent-connector' ),
		'sub'    => __( 'Every call and every refusal', 'zorem-ai-agent-connector' ),
		'icon'   => 'clipboard-list',
		'tint'   => '#ecfdf5',
		'accent' => '#059669',
	),
	array(
		'value'  => __( 'No setup', 'zorem-ai-agent-connector' ),
		'label'  => __( 'When you upgrade', 'zorem-ai-agent-connector' ),
		'sub'    => __( 'Your agent, token and permissions carry over', 'zorem-ai-agent-connector' ),
		'icon'   => 'star',
		'tint'   => '#fef3c7',
		'accent' => '#d97706',
	),
);

$ai_agent_os_powers = array(
	array(
		'icon'   => 'layers',
		'tint'   => '#eff6ff',
		'accent' => '#2563eb',
		'title'  => __( 'More agents', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'Give Claude, ChatGPT, Codex or each team its own agent, with its own permissions, token and MCP address. Revoke one without touching the others.', 'zorem-ai-agent-connector' ),
	),
	array(
		'icon'   => 'sparkles',
		'tint'   => '#f5f3ff',
		'accent' => '#7c3aed',
		'title'  => __( 'Ready-made profiles', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'Start an agent as Store Admin, Support, Fulfillment, Supplier or Shopping Agent, with the right permissions already set.', 'zorem-ai-agent-connector' ),
	),
	array(
		'icon'   => 'clipboard-list',
		'tint'   => '#ecfdf5',
		'accent' => '#059669',
		'title'  => __( 'Activity Log', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'See every call your agents make and every one that was refused, what was asked and what came back, with a 30-day summary and CSV export.', 'zorem-ai-agent-connector' ),
	),
	array(
		'icon'   => 'database',
		'tint'   => '#fce7f3',
		'accent' => '#db2777',
		'title'  => __( 'Website Brain', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'Your policies, chosen pages, store settings and installed plugins, ready for your agents to read — and you decide which agent reads what.', 'zorem-ai-agent-connector' ),
	),
	array(
		'icon'   => 'store',
		'tint'   => '#fef3c7',
		'accent' => '#d97706',
		'title'  => __( 'Shopping agents', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'Approve the agents AI Shopping Assistant proposes for your customers, each with its own permissions and customer data masked.', 'zorem-ai-agent-connector' ),
	),
	array(
		'icon'   => 'app-window',
		'tint'   => '#e0f2fe',
		'accent' => '#0284c7',
		'title'  => __( 'Site building', 'zorem-ai-agent-connector' ),
		'desc'   => __( 'Site title and tagline, homepage, logo, site icon, header menus, templates, colours and fonts — changed by asking.', 'zorem-ai-agent-connector' ),
	),
);

// Free vs PRO. True: included; a string: the limit in that edition.
$ai_agent_os_compare = array(
	array( __( 'Connect Claude, ChatGPT and Codex', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'WordPress tools (posts, pages, comments, media, menus, custom post types)', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Risk ratings, destructive switch and personal-data masking', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Exact None / Read / Write permissions per tool', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Agents', 'zorem-ai-agent-connector' ), __( '1', 'zorem-ai-agent-connector' ), __( 'Unlimited', 'zorem-ai-agent-connector' ) ),
	array( __( 'Ready-made agent profiles', 'zorem-ai-agent-connector' ), false, true ),
	array( __( 'Activity Log, 30-day summary and CSV export', 'zorem-ai-agent-connector' ), false, true ),
	array( __( 'Website Brain', 'zorem-ai-agent-connector' ), false, true ),
	array( __( 'WooCommerce tools (orders, products and order notes)', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Tools from your other plugins', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Site-building tools', 'zorem-ai-agent-connector' ), false, true ),
	array( __( 'Agents proposed by AI Shopping Assistant', 'zorem-ai-agent-connector' ), false, true ),
	array( __( 'Automatic updates', 'zorem-ai-agent-connector' ), true, true ),
	array( __( 'Priority email support', 'zorem-ai-agent-connector' ), false, true ),
);

$ai_agent_os_outcomes = array(
	array(
		'side'    => 'before',
		'eyebrow' => __( 'Zorem AI Agent Connector', 'zorem-ai-agent-connector' ),
		'title'   => __( 'One agent for every app', 'zorem-ai-agent-connector' ),
		'icon'    => 'info',
		'items'   => array(
			__( 'Every AI app shares the same agent and the same permissions', 'zorem-ai-agent-connector' ),
			__( 'No record of what the agent did, or what it was refused', 'zorem-ai-agent-connector' ),
			__( 'Every agent starts from scratch, with no ready-made profiles', 'zorem-ai-agent-connector' ),
			__( 'Agents start with no knowledge of your policies or pages', 'zorem-ai-agent-connector' ),
		),
	),
	array(
		'side'    => 'after',
		'eyebrow' => __( 'AI Agent OS PRO', 'zorem-ai-agent-connector' ),
		'title'   => __( 'Every agent, every tool, on record', 'zorem-ai-agent-connector' ),
		'icon'    => 'check-circle',
		'items'   => array(
			__( 'Each app or team gets its own agent and only the access it needs', 'zorem-ai-agent-connector' ),
			__( 'Every call and refusal in the Activity Log, ready to export', 'zorem-ai-agent-connector' ),
			__( 'Start agents as Store Admin, Support or Fulfillment in one click', 'zorem-ai-agent-connector' ),
			__( 'The Website Brain gives agents your policies, pages and settings', 'zorem-ai-agent-connector' ),
		),
	),
);
?>
<div class="aaos-gp">

	<?php // 1) Hero: eyebrow, headline, call to action, plan status. ?>
	<div class="aaos-gp-hero">
		<div class="aaos-gp-hero__orb aaos-gp-hero__orb--a"></div>
		<div class="aaos-gp-hero__orb aaos-gp-hero__orb--b"></div>
		<div class="aaos-gp-hero__orb aaos-gp-hero__orb--c"></div>

		<div class="aaos-gp-hero__inner">
			<span class="aaos-gp-hero__eyebrow">
				<?php \Zorem\UI\icon( 'sparkles' ); ?>
				<span><?php esc_html_e( 'Upgrade to AI Agent OS PRO', 'zorem-ai-agent-connector' ); ?></span>
			</span>
			<h1 class="aaos-gp-hero__title"><?php esc_html_e( 'Run AI agents across your whole site.', 'zorem-ai-agent-connector' ); ?></h1>
			<p class="aaos-gp-hero__lead">
				<?php esc_html_e( 'More agents, ready-made profiles, a full Activity Log, the Website Brain and site-building tools.', 'zorem-ai-agent-connector' ); ?>
			</p>

			<div class="aaos-gp-hero__actions">
				<a class="aaos-gp-btn aaos-gp-btn--primary" href="<?php echo esc_url( $ai_agent_os_upgrade ); ?>" target="_blank" rel="noopener noreferrer">
					<?php \Zorem\UI\icon( 'zap' ); ?>
					<span><?php esc_html_e( 'Get AI Agent OS PRO', 'zorem-ai-agent-connector' ); ?></span>
				</a>
			</div>

			<div class="aaos-gp-hero__trust">
				<span class="aaos-gp-hero__trust-item"><?php \Zorem\UI\icon( 'shield-check' ); ?><?php esc_html_e( 'Your agent and permissions carry over', 'zorem-ai-agent-connector' ); ?></span>
				<span class="aaos-gp-hero__trust-item"><?php \Zorem\UI\icon( 'message-square' ); ?><?php esc_html_e( 'Priority email support', 'zorem-ai-agent-connector' ); ?></span>			</div>
		</div>

		<div class="aaos-gp-hero__badge">
			<span class="aaos-gp-hero__badge-dot"></span>
			<div>
				<div class="aaos-gp-hero__badge-eyebrow"><?php esc_html_e( 'Plan status', 'zorem-ai-agent-connector' ); ?></div>
				<div class="aaos-gp-hero__badge-val"><?php esc_html_e( 'Free edition', 'zorem-ai-agent-connector' ); ?></div>
			</div>
		</div>
	</div>

	<?php // 2) Four facts. ?>
	<div class="aaos-gp-stats">
		<?php foreach ( $ai_agent_os_stats as $ai_agent_os_stat ) : ?>
			<div class="aaos-gp-stat">
				<span class="aaos-gp-stat__icon" style="background: <?php echo esc_attr( $ai_agent_os_stat['tint'] ); ?>; color: <?php echo esc_attr( $ai_agent_os_stat['accent'] ); ?>;">
					<?php \Zorem\UI\icon( $ai_agent_os_stat['icon'] ); ?>
				</span>
				<div class="aaos-gp-stat__num"><?php echo esc_html( $ai_agent_os_stat['value'] ); ?></div>
				<div class="aaos-gp-stat__label"><?php echo esc_html( $ai_agent_os_stat['label'] ); ?></div>
				<div class="aaos-gp-stat__sub"><?php echo esc_html( $ai_agent_os_stat['sub'] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<?php // 3) Six PRO features. ?>
	<div class="aaos-gp-section">
		<div class="aaos-gp-section__head">
			<span class="aaos-gp-section__eyebrow"><?php esc_html_e( 'Why AI Agent OS PRO', 'zorem-ai-agent-connector' ); ?></span>
			<h2 class="aaos-gp-section__title"><?php esc_html_e( 'Six things your agents can do with PRO', 'zorem-ai-agent-connector' ); ?></h2>
			<p class="aaos-gp-section__sub"><?php esc_html_e( 'Built for sites where AI does real work every day: more agents, more tools, and a record of everything they did.', 'zorem-ai-agent-connector' ); ?></p>
		</div>

		<div class="aaos-gp-powers">
			<?php foreach ( $ai_agent_os_powers as $ai_agent_os_power ) : ?>
				<div class="aaos-gp-power">
					<span class="aaos-gp-power__icon" style="background: <?php echo esc_attr( $ai_agent_os_power['tint'] ); ?>; color: <?php echo esc_attr( $ai_agent_os_power['accent'] ); ?>;">
						<?php \Zorem\UI\icon( $ai_agent_os_power['icon'] ); ?>
					</span>
					<h3 class="aaos-gp-power__title"><?php echo esc_html( $ai_agent_os_power['title'] ); ?></h3>
					<p class="aaos-gp-power__desc"><?php echo esc_html( $ai_agent_os_power['desc'] ); ?></p>
					<span class="aaos-gp-power__chip"><?php \Zorem\UI\icon( 'check' ); ?><?php esc_html_e( 'Included in PRO', 'zorem-ai-agent-connector' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // 4) Free vs PRO. ?>
	<div class="aaos-gp-section">
		<div class="aaos-gp-section__head">
			<span class="aaos-gp-section__eyebrow"><?php esc_html_e( 'Compare', 'zorem-ai-agent-connector' ); ?></span>
			<h2 class="aaos-gp-section__title"><?php esc_html_e( 'Zorem AI Agent Connector and AI Agent OS PRO', 'zorem-ai-agent-connector' ); ?></h2>
		</div>

		<div class="aaos-gp-compare">
			<div class="aaos-gp-compare__row aaos-gp-compare__row--head">
				<span><?php esc_html_e( 'Feature', 'zorem-ai-agent-connector' ); ?></span>
				<span><?php esc_html_e( 'Free', 'zorem-ai-agent-connector' ); ?><small><?php esc_html_e( 'Your plan', 'zorem-ai-agent-connector' ); ?></small></span>
				<span class="is-pro"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?><small><?php esc_html_e( 'Recommended', 'zorem-ai-agent-connector' ); ?></small></span>
			</div>
			<?php foreach ( $ai_agent_os_compare as $ai_agent_os_row ) : ?>
				<div class="aaos-gp-compare__row">
					<span class="aaos-gp-compare__feature"><?php echo esc_html( $ai_agent_os_row[0] ); ?></span>
					<?php foreach ( array( 1, 2 ) as $ai_agent_os_col ) : ?>
						<span class="aaos-gp-compare__cell<?php echo 2 === $ai_agent_os_col ? ' is-pro' : ''; ?>">
							<?php if ( true === $ai_agent_os_row[ $ai_agent_os_col ] ) : ?>
								<span class="aaos-gp-compare__yes" aria-label="<?php esc_attr_e( 'Included', 'zorem-ai-agent-connector' ); ?>"><?php \Zorem\UI\icon( 'check' ); ?></span>
							<?php elseif ( false === $ai_agent_os_row[ $ai_agent_os_col ] ) : ?>
								<span class="aaos-gp-compare__no" aria-label="<?php esc_attr_e( 'Not included', 'zorem-ai-agent-connector' ); ?>"><?php \Zorem\UI\icon( 'x' ); ?></span>
							<?php else : ?>
								<strong><?php echo esc_html( (string) $ai_agent_os_row[ $ai_agent_os_col ] ); ?></strong>
							<?php endif; ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // 5) Before and after. ?>
	<div class="aaos-gp-section">
		<div class="aaos-gp-section__head">
			<span class="aaos-gp-section__eyebrow"><?php esc_html_e( 'Before / After', 'zorem-ai-agent-connector' ); ?></span>
			<h2 class="aaos-gp-section__title"><?php esc_html_e( 'What changes the day you upgrade', 'zorem-ai-agent-connector' ); ?></h2>
		</div>

		<div class="aaos-gp-outcomes">
			<?php foreach ( $ai_agent_os_outcomes as $ai_agent_os_outcome ) : ?>
				<div class="aaos-gp-outcome aaos-gp-outcome--<?php echo esc_attr( $ai_agent_os_outcome['side'] ); ?>">
					<div class="aaos-gp-outcome__head">
						<span class="aaos-gp-outcome__icon"><?php \Zorem\UI\icon( $ai_agent_os_outcome['icon'] ); ?></span>
						<div>
							<span class="aaos-gp-outcome__eyebrow"><?php echo esc_html( $ai_agent_os_outcome['eyebrow'] ); ?></span>
							<h3 class="aaos-gp-outcome__title"><?php echo esc_html( $ai_agent_os_outcome['title'] ); ?></h3>
						</div>
					</div>
					<ul class="aaos-gp-outcome__list">
						<?php foreach ( $ai_agent_os_outcome['items'] as $ai_agent_os_item ) : ?>
							<li>
								<span class="aaos-gp-outcome__bullet"><?php \Zorem\UI\icon( 'before' === $ai_agent_os_outcome['side'] ? 'x' : 'check' ); ?></span>
								<span><?php echo esc_html( $ai_agent_os_item ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php // 6) Closing call to action. ?>
	<div class="aaos-gp-cta">
		<div class="aaos-gp-cta__orb"></div>
		<div class="aaos-gp-cta__content">
			<span class="aaos-gp-cta__eyebrow"><?php \Zorem\UI\icon( 'zap' ); ?><?php esc_html_e( 'Ready when you are', 'zorem-ai-agent-connector' ); ?></span>
			<h2 class="aaos-gp-cta__title"><?php esc_html_e( 'Unlock every agent feature for your site today', 'zorem-ai-agent-connector' ); ?></h2>
			<p class="aaos-gp-cta__sub"><?php esc_html_e( 'Install AI Agent OS PRO. Your Admin Assistant, its token, MCP address and permissions are already there.', 'zorem-ai-agent-connector' ); ?></p>
		</div>
		<div class="aaos-gp-cta__action">
			<a class="aaos-gp-btn aaos-gp-btn--primary aaos-gp-btn--xl" href="<?php echo esc_url( $ai_agent_os_upgrade ); ?>" target="_blank" rel="noopener noreferrer">
				<?php \Zorem\UI\icon( 'arrow-right' ); ?>
				<span><?php esc_html_e( 'Get started with PRO', 'zorem-ai-agent-connector' ); ?></span>
			</a>
		</div>
	</div>

</div>
