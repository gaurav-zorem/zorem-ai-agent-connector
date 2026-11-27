<?php
/**
 * Website Brain screen: header, tabs, and the active tab.
 *
 * The tabs are real links, one address per tab. admin.js follows them in
 * place (it fetches the address and swaps the tab bar and the body), so a
 * tab opens without a page reload; without JavaScript they still navigate.
 *
 * @package ZoremAgentBrain
 *
 * @var array<string, mixed> $zorem_agent_brain View data: tab, tabs, and the tab's own keys.
 */

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Admin\Screen;
use ZoremAgentBrain\V1\Admin\ScreenMount;

?>
<div class="zui-scope zorem-brain">
	<header class="zui-header">
		<div class="zui-header__bar">
			<div class="zui-header__lead">
				<div class="zui-brand zorem-brain__brand">
					<span class="zui-brand__emblem" aria-hidden="true"><?php function_exists( 'Zorem\UI\icon' ) ? \Zorem\UI\icon( 'database' ) : print( 'B' ); ?></span>
					<span class="zui-brand__name"><?php esc_html_e( 'Website Brain', 'zorem-ai-agent-connector' ); ?></span>
					<span class="zui-brand__tagline"><?php esc_html_e( 'What your assistants know, where it comes from, and who may read it', 'zorem-ai-agent-connector' ); ?></span>
				</div>
			</div>
		</div>
	</header>

	<nav class="zui-tabs" aria-label="<?php esc_attr_e( 'Website Brain sections', 'zorem-ai-agent-connector' ); ?>">
		<?php foreach ( $zorem_agent_brain['tabs'] as $zorem_agent_brain_slug => $zorem_agent_brain_label ) : ?>
			<?php $zorem_agent_brain_url = ScreenMount::url( $zorem_agent_brain_slug ); ?>
			<a class="zui-tabs__item<?php echo $zorem_agent_brain_slug === $zorem_agent_brain['tab'] ? ' is-active' : ''; ?>" href="<?php echo esc_url( $zorem_agent_brain_url ); ?>"<?php echo $zorem_agent_brain_slug === $zorem_agent_brain['tab'] ? ' aria-current="page"' : ''; ?>>
				<?php echo esc_html( $zorem_agent_brain_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<div class="zui-layout zui-layout--full">
		<main class="zui-content zui-content--full zorem-brain__body">
			<?php Screen::view( $zorem_agent_brain['tab'], $zorem_agent_brain ); ?>
		</main>
	</div>
</div>
