<?php
/**
 * The left sidebar inside a tab, and the panels it switches between.
 *
 * A tab that has more than one screenful of settings gets a `.zui-sidebar`.
 * The library switches sections itself — same deal as the top tabs — provided
 * `data-zui-section` appears on both the sidebar item and the `.zui-section`
 * panel, and provided both live inside the same `.zui-layout`.
 *
 * That last part is load-bearing and easy to miss. `zui.js` resolves the panel
 * container as `closest( '.zui-layout' )` first, falling back to
 * `.zui-tab-panel` and then `.zui-scope`. Six tabs each with their own sidebar
 * would cross-talk if any of them omitted the layout wrapper — clicking
 * "Diagnostics" in Settings would hide a panel in Knowledge. Every tab that
 * uses a sidebar wraps it in `.zui-layout`.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_render_sidebar' ) ) {
	/**
	 * Render the section nav.
	 *
	 * Entry shape: section key => array( 'label' => string, 'icon' => string ).
	 * The first entry is active unless one carries `'active' => true`.
	 *
	 * @param array<string, array<string, mixed>> $sections Section definitions.
	 * @param string                              $title    Sidebar heading.
	 */
	function ai_agent_os_render_sidebar( array $sections, string $title = '' ): void {
		$active = '';

		foreach ( $sections as $key => $section ) {
			if ( ! empty( $section['active'] ) ) {
				$active = (string) $key;
				break;
			}
		}

		if ( '' === $active ) {
			$active = (string) array_key_first( $sections );
		}
		?>
		<aside class="zui-sidebar">

			<?php
			/*
			 * The mobile drawer head. `data-zui-drawer-close` rather than the
			 * guide's `data-drawer-close` — see ZUI-USAGE.md disagreement 3;
			 * `zui.js` reads the prefixed form and the guide's Sidebar section
			 * shows the unprefixed one.
			 */
			?>
			<div class="zui-sidebar__mobile-head">
				<span class="zui-sidebar__mobile-title"><?php echo esc_html( '' !== $title ? $title : __( 'Sections', 'zorem-ai-agent-connector' ) ); ?></span>
				<button type="button" class="zui-sidebar__close" data-zui-drawer-close
					aria-label="<?php esc_attr_e( 'Close menu', 'zorem-ai-agent-connector' ); ?>">
					<?php \Zorem\UI\icon( 'x' ); ?>
				</button>
			</div>

			<nav class="zui-sidebar__nav" aria-label="<?php echo esc_attr( '' !== $title ? $title : __( 'Sections', 'zorem-ai-agent-connector' ) ); ?>">
				<?php foreach ( $sections as $ai_agent_os_key => $ai_agent_os_section ) : ?>
					<?php
					/*
					 * A section may head the group it starts. Used where a
					 * sidebar mixes the panel most people want with the ones
					 * kept for the people who need them — putting "Advanced"
					 * above the second group says which is which without
					 * hiding anything or reordering by guesswork.
					 */
					?>
					<?php if ( ! empty( $ai_agent_os_section['heading'] ) ) : ?>
						<span class="zui-sidebar__heading"><?php echo esc_html( (string) $ai_agent_os_section['heading'] ); ?></span>
					<?php endif; ?>

					<button type="button"
						class="zui-sidebar__item<?php echo ( (string) $ai_agent_os_key === $active ) ? ' is-active' : ''; ?>"
						data-zui-section="<?php echo esc_attr( (string) $ai_agent_os_key ); ?>"
						<?php echo ( (string) $ai_agent_os_key === $active ) ? 'aria-current="true"' : ''; ?>>
						<?php if ( ! empty( $ai_agent_os_section['icon'] ) ) : ?>
							<span class="zui-sidebar__icon"><?php \Zorem\UI\icon( (string) $ai_agent_os_section['icon'] ); ?></span>
						<?php endif; ?>
						<span class="zui-sidebar__label"><?php echo esc_html( (string) $ai_agent_os_section['label'] ); ?></span>
						<?php if ( isset( $ai_agent_os_section['badge'] ) && '' !== $ai_agent_os_section['badge'] ) : ?>
							<span class="zui-sidebar__badge"><?php echo esc_html( (string) $ai_agent_os_section['badge'] ); ?></span>
						<?php endif; ?>
					</button>
				<?php endforeach; ?>
			</nav>

		</aside>
		<?php
	}
}

if ( ! function_exists( 'ai_agent_os_section_open' ) ) {
	/**
	 * Open a `.zui-section` panel.
	 *
	 * @param string $key    Section key; must match a sidebar item.
	 * @param bool   $active Whether this section is the visible one.
	 */
	function ai_agent_os_section_open( string $key, bool $active = false ): void {
		printf(
			'<section class="zui-section%1$s" data-zui-section="%2$s"%3$s>',
			esc_attr( $active ? ' is-active' : '' ),
			esc_attr( $key ),
			esc_attr( $active ? '' : ' hidden' )
		);
	}
}

if ( ! function_exists( 'ai_agent_os_section_close' ) ) {
	/**
	 * Close a `.zui-section` panel.
	 */
	function ai_agent_os_section_close(): void {
		echo '</section>';
	}
}

if ( ! function_exists( 'ai_agent_os_local_datetime' ) ) {
	/**
	 * A stored UTC datetime, in the site's own format and timezone.
	 *
	 * Everything this plugin stores is UTC, and every screen was printing it
	 * raw — so a merchant in Kolkata read timestamps six hours off, in a format
	 * they never chose, and two different formats depending on the column.
	 *
	 * @param string|null $stored    UTC datetime, or null/empty for never.
	 * @param bool        $date_only Drop the time part.
	 * @return string Formatted, or "Never".
	 */
	function ai_agent_os_local_datetime( ?string $stored, bool $date_only = false ): string {
		$stored = trim( (string) $stored );

		if ( '' === $stored ) {
			return __( 'Never', 'zorem-ai-agent-connector' );
		}

		$timestamp = strtotime( $stored . ' UTC' );

		if ( false === $timestamp ) {
			return __( 'Never', 'zorem-ai-agent-connector' );
		}

		$format = $date_only
			? (string) get_option( 'date_format' )
			: (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' );

		return wp_date( $format, $timestamp );
	}
}
