<?php
/**
 * The admin shell: one page, every tab rendered once.
 *
 * Replaces the React SPA mount point. Each top tab renders into its own
 * `.zui-tab-panel`; the library's own JS switches between them on click, so a
 * tab change is a CSS show/hide with no fetch and no reload — and no plugin
 * JavaScript.
 *
 * ## Why every panel renders on every request
 *
 * The alternative — render the active tab, fetch the others — trades a little
 * first-paint work for a spinner on every tab click and a second code path that
 * can disagree with the first. Three panels of server-rendered markup is cheap;
 * two ways of producing the same screen is not.
 *
 * The cost is real and worth stating: a page load does the data-gathering for
 * every tab. If that becomes slow on a large site, the fix is to make the
 * expensive panel lazy *specifically*, with a measurement behind it, rather
 * than making them all lazy on principle.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

require_once AI_AGENT_OS_PATH . 'assets/zui/icons.php';
require_once __DIR__ . '/partials/brand.php';

/**
 * The top tabs, in order.
 *
 * Filterable so an add-on can register a panel without editing this file.
 *
 * Entry shape:
 *   label  : tab bar text.
 *   source : absolute path to the panel's render file.
 */
$ai_agent_os_tabs = apply_filters(
	'ai_agent_os_admin_tabs',
	array(
		'settings' => array(
			'label'  => __( 'Settings', 'zorem-ai-agent-connector' ),
			'source' => __DIR__ . '/tabs/settings.php',
		),
		'agents'   => array(
			'label'  => __( 'Agent', 'zorem-ai-agent-connector' ),
			'source' => __DIR__ . '/tabs/agents.php',
		),
		'status'   => array(
			'label'  => __( 'Status', 'zorem-ai-agent-connector' ),
			'source' => __DIR__ . '/tabs/status.php',
		),
	)
);

/*
 * Read-only view state. Nonce-free on purpose: which tab is showing changes
 * nothing, and demanding a nonce to look at a page teaches people to click
 * through nonce failures.
 */
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Read-only view state.
$ai_agent_os_active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

/*
 * The landing tab is named, not inferred from the order: Settings, which is
 * also first in the strip. Named so that reordering the tabs cannot change the
 * screen this plugin opens on by accident.
 */
$ai_agent_os_default_tab = isset( $ai_agent_os_tabs['settings'] )
	? 'settings'
	: (string) key( $ai_agent_os_tabs );

// Diagnostics moved from Settings to Status; an old link opens Status.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Read-only view state.
if ( 'settings' === $ai_agent_os_active_tab && isset( $_GET['section'] ) && 'diagnostics' === sanitize_key( wp_unslash( $_GET['section'] ) ) ) {
	$ai_agent_os_active_tab = 'status';
}

if ( ! isset( $ai_agent_os_tabs[ $ai_agent_os_active_tab ] ) ) {
	$ai_agent_os_active_tab = $ai_agent_os_default_tab;
}
?>
<div class="aaos-app zui-scope" id="aaos-app">

	<?php require __DIR__ . '/header.php'; ?>

	<?php foreach ( $ai_agent_os_tabs as $ai_agent_os_slug => $ai_agent_os_tab ) : ?>
		<div class="zui-tab-panel"
			data-zui-tab="<?php echo esc_attr( $ai_agent_os_slug ); ?>"
			<?php echo ( $ai_agent_os_slug === $ai_agent_os_active_tab ) ? '' : 'hidden'; ?>>
			<?php
			/*
			 * `file_exists` guarded so a bad registration through the filter
			 * cannot fatal the whole admin screen. An add-on with a typo should
			 * cost its own tab, not everyone else's.
			 */
			if ( isset( $ai_agent_os_tab['source'] ) && file_exists( $ai_agent_os_tab['source'] ) ) {
				require $ai_agent_os_tab['source'];
			}
			?>
		</div>
	<?php endforeach; ?>

	<?php
	/*
	 * Page chrome, rendered once.
	 *
	 * The entry dialog is opened from two tabs — a citation on Assistant, a row
	 * on Knowledge — and it was first written into each of them. Every panel
	 * renders on every request, so that put **two elements with the same id** in
	 * the document, and `ZUI.modal.open()` resolves by `getElementById`: it
	 * found the first, which was inside a hidden tab panel, and opened it there.
	 *
	 * The symptom was a citation click that did nothing at all. Nothing threw,
	 * nothing logged, and the dialog really had opened — somewhere nobody could
	 * see. Found by a Playwright walkthrough asserting the dialog was visible
	 * rather than that the click had happened.
	 *
	 * A dialog belongs to the page, not to a panel. Anything shared between tabs
	 * goes here for the same reason.
	 */
	?>

</div>
