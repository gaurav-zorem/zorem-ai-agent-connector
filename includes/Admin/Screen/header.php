<?php
/**
 * The page chrome: brand, header actions, and the top tab bar.
 *
 * Canonical `.zui-header` markup per the library's implementation guide
 * (assets/zui/ALL-ZUI-IMPLEMENTATION-GUIDE.md § Header). The structure is not
 * decorative — the component CSS selects on `__bar`, `__lead`, `.zui-brand` and
 * their children, so a rearranged tree silently loses its styling.
 *
 * @package AiAgentOs
 *
 * @var array<string, array<string, mixed>> $ai_agent_os_tabs       Tab slug => definition.
 * @var string                              $ai_agent_os_active_tab Active tab slug.
 */

defined( 'ABSPATH' ) || exit;

$ai_agent_os_brand_info = ai_agent_os_brand();
?>
<header class="zui-header" id="aaos-header">

	<div class="zui-header__bar">

		<div class="zui-header__lead">
			<div class="zui-brand"
				style="--zui-brand-emblem-bg: <?php echo esc_attr( $ai_agent_os_brand_info['emblem_bg'] ); ?>; --zui-brand-emblem-color: <?php echo esc_attr( $ai_agent_os_brand_info['emblem_color'] ); ?>;">
				<span class="zui-brand__emblem" aria-hidden="true">
					<?php \Zorem\UI\icon( $ai_agent_os_brand_info['icon'] ); ?>
				</span>
				<span class="zui-brand__name"><?php echo esc_html( $ai_agent_os_brand_info['name'] ); ?></span>
				<?php if ( '' !== $ai_agent_os_brand_info['badge'] ) : ?>
					<span class="zui-brand__badge"><?php echo esc_html( $ai_agent_os_brand_info['badge'] ); ?></span>
				<?php endif; ?>
				<span class="zui-brand__tagline"><?php echo esc_html( $ai_agent_os_brand_info['tagline'] ); ?></span>
			</div>
		</div>

		<div class="zui-header-actions">
			<a class="zui-header-action zui-header-action--with-label"
				href="https://docs.zorem.com/" target="_blank" rel="noreferrer noopener">
				<?php \Zorem\UI\icon( 'book-open' ); ?>
				<span class="zui-header-action__label"><?php esc_html_e( 'Docs Portal', 'zorem-ai-agent-connector' ); ?></span>
			</a>
		</div>

	</div>

	<?php
	/*
	 * `data-zui-tab` rather than `data-tab`, and buttons rather than links.
	 *
	 * The library's own JS (`_wireShell`) reads `data-zui-tab` on both the tab
	 * item and the panel, and does the whole show/hide itself — so tab switching
	 * needs no plugin JavaScript at all. A consumer that hand-rolls its own
	 * switcher against `data-tab` carries a different attribute on its panels;
	 * that is its switcher, not the library's.
	 *
	 * See ZUI-USAGE.md: the guide's Tab Panel section shows `data-tab` in its
	 * required structure and its Tabs section shows `data-zui-tab`. The JS is
	 * the tiebreaker.
	 */
	?>
	<nav class="zui-tabs" aria-label="<?php esc_attr_e( 'Plugin sections', 'zorem-ai-agent-connector' ); ?>">
		<?php foreach ( $ai_agent_os_tabs as $ai_agent_os_slug => $ai_agent_os_tab ) : ?>
			<button type="button"
				class="zui-tabs__item<?php echo ( $ai_agent_os_slug === $ai_agent_os_active_tab ) ? ' is-active' : ''; ?>"
				data-zui-tab="<?php echo esc_attr( $ai_agent_os_slug ); ?>"
				<?php echo ( $ai_agent_os_slug === $ai_agent_os_active_tab ) ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $ai_agent_os_tab['label'] ); ?>
				<?php if ( ! empty( $ai_agent_os_tab['pro'] ) ) : ?>
					<span class="zui-pro-feature__badge aaos-tab-pro"><?php esc_html_e( 'PRO', 'zorem-ai-agent-connector' ); ?></span>
				<?php endif; ?>
			</button>
		<?php endforeach; ?>
	</nav>

</header>
