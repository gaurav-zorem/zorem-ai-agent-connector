<?php
/**
 * Diagnostics — H16 of the migration's honesty inventory.
 *
 * Every counter here exists because something was silently wrong once. The
 * rule for this file, restated because it is the whole point: **a value the
 * React screen showed must still be shown, and a value that means "we could
 * not look" must not be rendered as "there are none".**
 *
 * The facts are grouped by what they are about — connections, this install,
 * WooCommerce, safety, the shared bridge — each group a card, each row a
 * name, a value and, where there is something to judge, a status badge. The
 * Copy diagnostics button is in the Status header; its payload is printed at
 * the end of this file.
 *
 * @package AiAgentOs
 *
 * @var array<string, mixed> $ai_agent_os_diagnostics Diagnostics payload.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $ai_agent_os_diagnostics ) ) :
	?>
	<div class="zui-notice zui-notice--warning" id="aaos-diagnostics">
		<?php esc_html_e( 'Diagnostics could not be read. That means this panel has no information, not that there is nothing to report.', 'zorem-ai-agent-connector' ); ?>
	</div>
	<?php
	wp_add_inline_script( 'ai-agent-os-admin', 'window.aiAgentOsDiagnostics = "{}";', 'before' );

	return;
endif;

$ai_agent_os_diag    = $ai_agent_os_diagnostics;
$ai_agent_os_adapter = (array) ( $ai_agent_os_diag['adapter'] ?? array() );
$ai_agent_os_schema  = (array) ( $ai_agent_os_diag['schema'] ?? array() );
$ai_agent_os_routes  = (array) ( $ai_agent_os_diag['foreign_mcp_routes'] ?? array() );
$ai_agent_os_missing = (array) ( $ai_agent_os_schema['missing_tables'] ?? array() );
$ai_agent_os_bridge  = \ZoremAgentBridge\V1\Diagnostics\Diagnostics::report();

$ai_agent_os_provenance = array(
	'woocommerce' => __( 'Provided by WooCommerce', 'zorem-ai-agent-connector' ),
	'bundled'     => __( 'Bundled with this plugin', 'zorem-ai-agent-connector' ),
	'external'    => __( 'Provided by another plugin', 'zorem-ai-agent-connector' ),
	'unknown'     => __( 'Unknown source', 'zorem-ai-agent-connector' ),
	'none'        => __( 'Not found', 'zorem-ai-agent-connector' ),
);

/*
 * One row: `label`; `value` (plain text) or `code` (a machine value, shown as
 * a chip, or a list of them); optional `note` lines; optional `state`
 * (ok, warning, danger) for the badge. Built here so the markup below has no
 * logic in it.
 */
$ai_agent_os_prov      = (string) ( $ai_agent_os_adapter['provenance'] ?? 'unknown' );
$ai_agent_os_adapter_n = array();

if ( ! empty( $ai_agent_os_adapter['available'] ) && empty( $ai_agent_os_adapter['version_matches'] ) ) {
	/* translators: %s: bundled adapter version. */
	$ai_agent_os_adapter_n[] = sprintf( __( 'This differs from the version bundled with this plugin (%s).', 'zorem-ai-agent-connector' ), (string) ( $ai_agent_os_adapter['bundled_version'] ?? '' ) );
}

$ai_agent_os_route_list = array_map(
	static fn ( $route ): string => (string) ( $route['route'] ?? $route['id'] ?? '' ),
	$ai_agent_os_routes
);

$ai_agent_os_route_note = array();

if ( array() !== $ai_agent_os_route_list ) {
	$ai_agent_os_route_note[] = __( 'These are additional doors into the same abilities, governed by their own rules rather than yours. Agent-authenticated calls through them are still refused, but you should know they exist.', 'zorem-ai-agent-connector' );
} elseif ( ! empty( $ai_agent_os_diag['foreign_mcp_error'] ) ) {
	/* translators: %s: error message. */
	$ai_agent_os_route_note[] = sprintf( __( 'Detection failed (%s), so this means “we could not look”, not “there are none”.', 'zorem-ai-agent-connector' ), (string) $ai_agent_os_diag['foreign_mcp_error'] );
}

$ai_agent_os_woo = (string) ( $ai_agent_os_diag['woocommerce'] ?? '' );

$ai_agent_os_groups = array(
	array(
		'title' => __( 'Connections', 'zorem-ai-agent-connector' ),
		'icon'  => 'globe',
		'rows'  => array(
			array(
				'label' => __( 'MCP addresses', 'zorem-ai-agent-connector' ),
				'code'  => (string) ( $ai_agent_os_diag['mcp_endpoint'] ?? '' ),
				'note'  => array( __( 'The agent’s own address sits under this path. Copy it from its Connection section.', 'zorem-ai-agent-connector' ) ),
			),
			array(
				'label' => __( 'MCP Adapter', 'zorem-ai-agent-connector' ),
				'value' => ! empty( $ai_agent_os_adapter['available'] )
					/* translators: 1: adapter version, 2: where the adapter came from. */
					? sprintf( __( '%1$s — %2$s', 'zorem-ai-agent-connector' ), (string) ( $ai_agent_os_adapter['version'] ?? '?' ), $ai_agent_os_provenance[ $ai_agent_os_prov ] ?? $ai_agent_os_prov )
					: $ai_agent_os_provenance['none'],
				'code'  => (string) ( $ai_agent_os_adapter['path'] ?? '' ),
				'note'  => $ai_agent_os_adapter_n,
				'state' => ! empty( $ai_agent_os_adapter['available'] ) ? 'ok' : 'danger',
			),
			array(
				'label' => __( 'Other MCP endpoints', 'zorem-ai-agent-connector' ),
				'value' => array() === $ai_agent_os_route_list ? __( 'None detected', 'zorem-ai-agent-connector' ) : '',
				'code'  => $ai_agent_os_route_list,
				'note'  => $ai_agent_os_route_note,
				'state' => array() === $ai_agent_os_route_list ? 'ok' : 'warning',
			),
		),
	),
	array(
		'title' => __( 'This install', 'zorem-ai-agent-connector' ),
		'icon'  => 'server',
		'rows'  => array(
			array(
				'label' => __( 'PHP / WordPress', 'zorem-ai-agent-connector' ),
				'value' => sprintf( '%s / %s', (string) ( $ai_agent_os_diag['php_version'] ?? '' ), (string) ( $ai_agent_os_diag['wp_version'] ?? '' ) ),
			),
			array(
				'label' => __( 'Database tables', 'zorem-ai-agent-connector' ),
				'value' => array() === $ai_agent_os_missing
					/* translators: %s: schema version. */
					? sprintf( __( 'All present (schema %s).', 'zorem-ai-agent-connector' ), (string) ( $ai_agent_os_schema['db_version'] ?? '' ) )
					: __( 'Missing', 'zorem-ai-agent-connector' ),
				'code'  => array() === $ai_agent_os_missing ? '' : array_map( 'strval', $ai_agent_os_missing ),
				'note'  => array() === $ai_agent_os_missing ? array() : array( __( 'The database refused to create these tables. The agent cannot be governed until they exist; deactivating and reactivating the plugin retries.', 'zorem-ai-agent-connector' ) ),
				'state' => array() === $ai_agent_os_missing ? 'ok' : 'danger',
			),
		),
	),
	array(
		'title' => __( 'WooCommerce', 'zorem-ai-agent-connector' ),
		'icon'  => 'store',
		'rows'  => array(
			array(
				'label' => __( 'WooCommerce', 'zorem-ai-agent-connector' ),
				'value' => '' !== $ai_agent_os_woo ? $ai_agent_os_woo : __( 'Not active', 'zorem-ai-agent-connector' ),
			),
		),
	),
	array(
		'title' => __( 'Safety', 'zorem-ai-agent-connector' ),
		'icon'  => 'lock',
		'rows'  => array(
			array(
				'label' => __( 'Redacted fields', 'zorem-ai-agent-connector' ),
				/* translators: 1: field count, 2: ability count. */
				'value' => sprintf( __( '%1$d fields redacted across %2$d abilities.', 'zorem-ai-agent-connector' ), (int) ( $ai_agent_os_diag['pii_field_count'] ?? 0 ), count( (array) ( $ai_agent_os_diag['pii_abilities'] ?? array() ) ) ),
			),
			array(
				'label' => __( 'Security salts', 'zorem-ai-agent-connector' ),
				'value' => empty( $ai_agent_os_diag['salt_rotated'] ) ? __( 'Unchanged — agent tokens work.', 'zorem-ai-agent-connector' ) : __( 'Changed — the agent needs a new token.', 'zorem-ai-agent-connector' ),
				'state' => empty( $ai_agent_os_diag['salt_rotated'] ) ? 'ok' : 'danger',
			),
		),
	),
	array(
		'title' => __( 'Agent Runtime Bridge', 'zorem-ai-agent-connector' ),
		'icon'  => 'layers',
		'rows'  => array(
			array(
				'label' => __( 'Version', 'zorem-ai-agent-connector' ),
				/* translators: 1: bridge version, 2: number of bundled copies. */
				'value' => sprintf( _n( '%1$s (%2$s bundled copy)', '%1$s (%2$s bundled copies)', count( $ai_agent_os_bridge['copies'] ), 'zorem-ai-agent-connector' ), (string) $ai_agent_os_bridge['version'], number_format_i18n( count( $ai_agent_os_bridge['copies'] ) ) ),
			),
			array(
				'label' => __( 'Features', 'zorem-ai-agent-connector' ),
				'code'  => array_map(
					static fn ( string $id, string $about ): string => $id . ' ' . $about,
					array_keys( $ai_agent_os_bridge['features'] ),
					array_values( $ai_agent_os_bridge['features'] )
				),
			),
			array(
				'label' => __( 'Providers', 'zorem-ai-agent-connector' ),
				'code'  => array_map(
					static fn ( string $contract, string $owner ): string => $contract . ': ' . $owner,
					array_keys( $ai_agent_os_bridge['providers'] ),
					array_values( $ai_agent_os_bridge['providers'] )
				),
			),
			array(
				'label' => __( 'Provider conflicts', 'zorem-ai-agent-connector' ),
				'value' => array() === $ai_agent_os_bridge['conflicts'] ? __( 'None', 'zorem-ai-agent-connector' ) : '',
				'code'  => array_map(
					static fn ( string $contract, array $owners ): string => $contract . ': ' . implode( ', ', $owners ),
					array_keys( $ai_agent_os_bridge['conflicts'] ),
					array_values( $ai_agent_os_bridge['conflicts'] )
				),
				'state' => array() === $ai_agent_os_bridge['conflicts'] ? 'ok' : 'danger',
			),
		),
	),
);

$ai_agent_os_badges = array(
	'ok'      => array( 'success', __( 'OK', 'zorem-ai-agent-connector' ) ),
	'warning' => array( 'warning', __( 'Check', 'zorem-ai-agent-connector' ) ),
	'danger'  => array( 'danger', __( 'Problem', 'zorem-ai-agent-connector' ) ),
);
?>
<div class="aaos-status__groups" id="aaos-diagnostics">
	<?php foreach ( $ai_agent_os_groups as $ai_agent_os_group ) : ?>
		<section class="zui-card aaos-status__group">
			<div class="zui-card__head">
				<h3 class="zui-card__title"><?php \Zorem\UI\icon( $ai_agent_os_group['icon'] ); ?><span><?php echo esc_html( $ai_agent_os_group['title'] ); ?></span></h3>
			</div>
			<dl class="aaos-status__rows">
				<?php foreach ( $ai_agent_os_group['rows'] as $ai_agent_os_row ) : ?>
					<?php
					$ai_agent_os_codes = (array) ( $ai_agent_os_row['code'] ?? array() );
					$ai_agent_os_codes = array_values( array_filter( $ai_agent_os_codes, static fn ( $c ): bool => '' !== (string) $c ) );
					$ai_agent_os_state = (string) ( $ai_agent_os_row['state'] ?? '' );
					?>
					<div class="aaos-status__row">
						<dt><?php echo esc_html( $ai_agent_os_row['label'] ); ?></dt>
						<dd>
							<?php if ( '' !== (string) ( $ai_agent_os_row['value'] ?? '' ) ) : ?>
								<span class="aaos-status__value"><?php echo esc_html( (string) $ai_agent_os_row['value'] ); ?></span>
							<?php endif; ?>
							<?php if ( array() !== $ai_agent_os_codes ) : ?>
								<span class="aaos-status__codes">
									<?php foreach ( $ai_agent_os_codes as $ai_agent_os_code ) : ?>
										<code><?php echo esc_html( (string) $ai_agent_os_code ); ?></code>
									<?php endforeach; ?>
								</span>
							<?php endif; ?>
							<?php foreach ( (array) ( $ai_agent_os_row['note'] ?? array() ) as $ai_agent_os_note ) : ?>
								<span class="aaos-status__note"><?php echo esc_html( (string) $ai_agent_os_note ); ?></span>
							<?php endforeach; ?>
						</dd>
						<?php if ( isset( $ai_agent_os_badges[ $ai_agent_os_state ] ) ) : ?>
							<span class="zui-badge zui-badge--<?php echo esc_attr( $ai_agent_os_badges[ $ai_agent_os_state ][0] ); ?> aaos-status__badge"><?php echo esc_html( $ai_agent_os_badges[ $ai_agent_os_state ][1] ); ?></span>
						<?php else : ?>
							<span class="aaos-status__badge" aria-hidden="true"></span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</dl>
		</section>
	<?php endforeach; ?>
</div>

<?php
/*
 * The payload the Copy diagnostics button reads. Attached to the screen's own
 * script rather than printed as a tag: this panel renders in the body, the
 * script is enqueued in the footer, so the inline code is still printed before
 * the script that reads it. It is the same array the panel above displays, so
 * the two cannot drift.
 */
wp_add_inline_script(
	'ai-agent-os-admin',
	'window.aiAgentOsDiagnostics = ' . wp_json_encode(
		(string) wp_json_encode( $ai_agent_os_diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	) . ';',
	'before'
);
