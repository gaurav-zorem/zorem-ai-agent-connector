<?php
/**
 * Website Brain: sources.
 *
 * @package ZoremAgentBrain
 *
 * @var array<string, mixed> $zorem_agent_brain View data; `sources` from the /sources route, `settings`.
 */

defined( 'ABSPATH' ) || exit;

$zorem_agent_brain_sources  = $zorem_agent_brain['sources'];
$zorem_agent_brain_settings = $zorem_agent_brain['settings'];

/**
 * A settings toggle wired to the /settings route.
 *
 * @param string $key        Setting key.
 * @param bool   $on         Current value.
 * @param string $label      Screen-reader label.
 * @param string $confirm_on Confirmation shown when switching on, '' for none.
 */
$zorem_agent_brain_toggle = static function ( string $key, bool $on, string $label, string $confirm_on = '' ): void {
	?>
	<label class="zui-toggle">
		<input type="checkbox" class="zui-toggle__input" value="1" data-zorem-brain-setting="<?php echo esc_attr( $key ); ?>" data-confirm-on="<?php echo esc_attr( $confirm_on ); ?>" aria-label="<?php echo esc_attr( $label ); ?>" <?php checked( $on ); ?>>
		<span class="zui-toggle__track"><span class="zui-toggle__thumb"></span></span>
	</label>
	<?php
};
?>
<section class="zui-section">

	<?php /* ---- Plugins and bundled documentation. ---- */ ?>
	<div class="zui-card">
		<div class="zui-card__head"><h2 class="zui-card__title"><?php esc_html_e( 'Installed plugins', 'zorem-ai-agent-connector' ); ?></h2></div>
		<div class="zui-card__body">
			<div class="zui-row zui-row--inline">
				<div class="zui-row__head">
					<span class="zui-row__label"><?php esc_html_e( 'Read installed plugins', 'zorem-ai-agent-connector' ); ?></span>
					<p class="zui-row__desc"><?php esc_html_e( 'Learns what each active plugin does, from its files. It never runs their code, and works on its own in the background.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<div class="zui-row__control">
					<?php $zorem_agent_brain_toggle( 'knowledge_enabled', ! empty( $zorem_agent_brain_settings['knowledge_enabled'] ), __( 'Read installed plugins', 'zorem-ai-agent-connector' ) ); ?>
				</div>
			</div>
			<div class="zui-row zui-row--inline">
				<div class="zui-row__head">
					<span class="zui-row__label"><?php esc_html_e( 'Bundled documentation', 'zorem-ai-agent-connector' ); ?></span>
					<p class="zui-row__desc"><?php esc_html_e( 'Ready-made help notes for big plugins such as WooCommerce, included with this plugin.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<div class="zui-row__control">
					<?php $zorem_agent_brain_toggle( 'knowledge_curated_kb', ! empty( $zorem_agent_brain_settings['knowledge_curated_kb'] ), __( 'Bundled documentation', 'zorem-ai-agent-connector' ) ); ?>
				</div>
			</div>
			<?php foreach ( (array) ( $zorem_agent_brain_sources['curated']['plugins'] ?? array() ) as $zorem_agent_brain_covered ) : ?>
				<div class="zui-row zui-row--inline">
					<div class="zui-row__head">
						<span class="zui-row__label"><?php echo esc_html( (string) $zorem_agent_brain_covered['name'] ); ?></span>
						<p class="zui-row__desc">
							<?php
							echo esc_html(
								$zorem_agent_brain_covered['local']
									? __( 'Uses what was learned from this site\'s own copy of the plugin.', 'zorem-ai-agent-connector' )
									: __( 'Uses the ready-made help notes. Switch only if your copy has been customised.', 'zorem-ai-agent-connector' )
							);
							?>
						</p>
					</div>
					<div class="zui-row__control">
						<?php
						$zorem_agent_brain_override = array(
							'plugin'    => (string) $zorem_agent_brain_covered['slug'],
							'use_local' => ! $zorem_agent_brain_covered['local'],
						);
						?>
						<button type="button" class="zui-btn-soft" data-zorem-brain-action data-route="sources/curated/local" data-reload="1"
							data-payload="<?php echo esc_attr( (string) wp_json_encode( $zorem_agent_brain_override ) ); ?>">
							<?php $zorem_agent_brain_covered['local'] ? esc_html_e( 'Use bundled documentation', 'zorem-ai-agent-connector' ) : esc_html_e( 'Use this site\'s own knowledge', 'zorem-ai-agent-connector' ); ?>
						</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
