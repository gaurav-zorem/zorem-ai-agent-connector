<?php
/**
 * Website Brain: entries.
 *
 * @package ZoremAgentBrain
 *
 * @var array<string, mixed> $zorem_agent_brain View data: filters, entries (/entries route), sections, kinds.
 */

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Admin\Screen;
use ZoremAgentBrain\V1\Admin\ScreenMount;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

$zorem_agent_brain_filters = $zorem_agent_brain['filters'];
$zorem_agent_brain_items   = $zorem_agent_brain['entries']['items'];
$zorem_agent_brain_total   = (int) $zorem_agent_brain['entries']['total'];
$zorem_agent_brain_pages   = max( 1, (int) ceil( $zorem_agent_brain_total / 25 ) );
$zorem_agent_brain_action  = admin_url( 'admin.php' );
?>
<section class="zui-section">
	<form method="get" action="<?php echo esc_url( $zorem_agent_brain_action ); ?>" class="zui-filter-bar">
		<input type="hidden" name="page" value="<?php echo esc_attr( ScreenMount::PAGE_SLUG ); ?>">
		<input type="hidden" name="tab" value="entries">
		<div class="zui-select-wrap">
			<select class="zui-select" name="source" aria-label="<?php esc_attr_e( 'Source', 'zorem-ai-agent-connector' ); ?>">
				<option value=""><?php esc_html_e( 'All sources', 'zorem-ai-agent-connector' ); ?></option>
				<?php foreach ( $zorem_agent_brain['kinds'] as $zorem_agent_brain_kind ) : ?>
					<option value="<?php echo esc_attr( $zorem_agent_brain_kind ); ?>" <?php selected( $zorem_agent_brain_filters['source'], $zorem_agent_brain_kind ); ?>><?php echo esc_html( $zorem_agent_brain_kind ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="zui-select-chevron"><?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'chevron-down' ); ?></span>
		</div>
		<div class="zui-select-wrap">
			<select class="zui-select" name="section" aria-label="<?php esc_attr_e( 'Section', 'zorem-ai-agent-connector' ); ?>">
				<option value=""><?php esc_html_e( 'All sections', 'zorem-ai-agent-connector' ); ?></option>
				<?php foreach ( $zorem_agent_brain['sections'] as $zorem_agent_brain_section ) : ?>
					<option value="<?php echo esc_attr( $zorem_agent_brain_section ); ?>" <?php selected( $zorem_agent_brain_filters['section'], $zorem_agent_brain_section ); ?>><?php echo esc_html( $zorem_agent_brain_section ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="zui-select-chevron"><?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'chevron-down' ); ?></span>
		</div>
		<div class="zui-select-wrap">
			<select class="zui-select" name="trust" aria-label="<?php esc_attr_e( 'Status', 'zorem-ai-agent-connector' ); ?>">
				<option value=""><?php esc_html_e( 'Approved and drafts', 'zorem-ai-agent-connector' ); ?></option>
				<option value="<?php echo esc_attr( KnowledgeEntry::TRUST_APPROVED ); ?>" <?php selected( $zorem_agent_brain_filters['trust'], KnowledgeEntry::TRUST_APPROVED ); ?>><?php esc_html_e( 'Approved', 'zorem-ai-agent-connector' ); ?></option>
				<option value="<?php echo esc_attr( KnowledgeEntry::TRUST_DRAFT ); ?>" <?php selected( $zorem_agent_brain_filters['trust'], KnowledgeEntry::TRUST_DRAFT ); ?>><?php esc_html_e( 'Drafts', 'zorem-ai-agent-connector' ); ?></option>
			</select>
			<span class="zui-select-chevron"><?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'chevron-down' ); ?></span>
		</div>
		<input type="search" class="zui-input" name="s" value="<?php echo esc_attr( $zorem_agent_brain_filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search entries…', 'zorem-ai-agent-connector' ); ?>">
		<label class="zorem-brain__check">
			<input type="checkbox" name="inactive" value="1" <?php checked( (bool) $zorem_agent_brain_filters['inactive'] ); ?>>
			<span><?php esc_html_e( 'Show switched-off plugins', 'zorem-ai-agent-connector' ); ?></span>
		</label>
		<button type="submit" class="zui-btn-secondary"><?php esc_html_e( 'Filter', 'zorem-ai-agent-connector' ); ?></button>
	</form>

	<div class="zui-card">
		<?php if ( array() === $zorem_agent_brain_items ) : ?>
			<div class="zorem-brain__empty">
				<span class="zorem-brain__emptyicon dashicons dashicons-search" aria-hidden="true"></span>
				<p class="zorem-brain__emptytitle"><?php esc_html_e( 'No entries match.', 'zorem-ai-agent-connector' ); ?></p>
				<p class="zorem-brain__emptyhint">
					<?php if ( $zorem_agent_brain_filters['inactive'] ) : ?>
						<?php esc_html_e( 'Try another source or section, or clear the search.', 'zorem-ai-agent-connector' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Try another source or section, clear the search, or include switched-off plugins.', 'zorem-ai-agent-connector' ); ?>
					<?php endif; ?>
				</p>
			</div>
		<?php else : ?>
			<div class="zui-table-scroll">
				<table class="zui-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Entry', 'zorem-ai-agent-connector' ); ?></th>
							<th><?php esc_html_e( 'Source', 'zorem-ai-agent-connector' ); ?></th>
							<th><?php esc_html_e( 'Section', 'zorem-ai-agent-connector' ); ?></th>
							<th><?php esc_html_e( 'Status', 'zorem-ai-agent-connector' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'zorem-ai-agent-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $zorem_agent_brain_items as $zorem_agent_brain_entry ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $zorem_agent_brain_entry['title'] ); ?></strong>
									<small><?php echo esc_html( '' !== $zorem_agent_brain_entry['plugin'] ? $zorem_agent_brain_entry['plugin'] . ' · ' . $zorem_agent_brain_entry['entry_key'] : $zorem_agent_brain_entry['entry_key'] ); ?></small>
								</td>
								<td><span class="zui-badge zui-badge--outline"><?php echo esc_html( $zorem_agent_brain_entry['source'] ); ?></span></td>
								<td><?php echo esc_html( $zorem_agent_brain_entry['section'] ); ?></td>
								<td>
									<?php if ( KnowledgeEntry::TRUST_DRAFT === $zorem_agent_brain_entry['trust'] ) : ?>
										<span class="zui-badge zui-badge--warning"><?php esc_html_e( 'Draft', 'zorem-ai-agent-connector' ); ?></span>
									<?php elseif ( $zorem_agent_brain_entry['stale'] ) : ?>
										<span class="zui-badge zui-badge--neutral"><?php esc_html_e( 'Plugin switched off: not used', 'zorem-ai-agent-connector' ); ?></span>
										<?php $zorem_agent_brain_tip = __( 'This plugin is switched off, so the assistants do not use this entry. It is used again when the plugin is switched back on.', 'zorem-ai-agent-connector' ); ?>
										<span class="zui-tooltip zorem-brain__entrytip" tabindex="0" role="img" aria-label="<?php echo esc_attr( $zorem_agent_brain_tip ); ?>">
											<?php function_exists( 'Zorem\UI\icon' ) && \Zorem\UI\icon( 'help-circle' ); ?>
											<span class="zui-tooltip__bubble">
												<?php echo esc_html( $zorem_agent_brain_tip ); ?>
												<span class="zui-tooltip__arrow"></span>
											</span>
										</span>
									<?php elseif ( $zorem_agent_brain_entry['superseded'] ) : ?>
										<span class="zui-badge zui-badge--neutral"><?php esc_html_e( 'Replaced by better source', 'zorem-ai-agent-connector' ); ?></span>
									<?php else : ?>
										<span class="zui-badge zui-badge--success"><?php esc_html_e( 'In use', 'zorem-ai-agent-connector' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( Screen::local_time( $zorem_agent_brain_entry['updated_at'] ) ); ?></td>
								<td><button type="button" class="zui-btn-ghost" data-zorem-brain-entry="<?php echo esc_attr( (string) $zorem_agent_brain_entry['id'] ); ?>"><?php esc_html_e( 'View', 'zorem-ai-agent-connector' ); ?></button></td>
							</tr>
							<tr hidden data-zorem-brain-entry-row="<?php echo esc_attr( (string) $zorem_agent_brain_entry['id'] ); ?>">
								<td colspan="6"><pre class="zorem-brain__content" data-zorem-brain-entry-body="<?php echo esc_attr( (string) $zorem_agent_brain_entry['id'] ); ?>"></pre></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<?php if ( $zorem_agent_brain_pages > 1 ) : ?>
		<?php
		/*
		 * The ZUI pagination component: numbered buttons with Previous / Next,
		 * the first and last page always shown, two pages either side of the
		 * current one, and a gap where pages are skipped. Each is a real link,
		 * which admin.js opens in place.
		 */
		$zorem_agent_brain_current = min( max( 1, (int) $zorem_agent_brain_filters['paged'] ), $zorem_agent_brain_pages );
		$zorem_agent_brain_numbers = array();

		for ( $zorem_agent_brain_n = 1; $zorem_agent_brain_n <= $zorem_agent_brain_pages; $zorem_agent_brain_n++ ) {
			if ( 1 === $zorem_agent_brain_n || $zorem_agent_brain_pages === $zorem_agent_brain_n || abs( $zorem_agent_brain_n - $zorem_agent_brain_current ) <= 2 ) {
				$zorem_agent_brain_numbers[] = $zorem_agent_brain_n;
			} elseif ( end( $zorem_agent_brain_numbers ) !== 0 ) {
				$zorem_agent_brain_numbers[] = 0; // A gap.
			}
		}

		$zorem_agent_brain_first = ( $zorem_agent_brain_current - 1 ) * 25 + 1;
		$zorem_agent_brain_last  = min( $zorem_agent_brain_total, $zorem_agent_brain_current * 25 );
		?>
		<nav class="zui-pagination zorem-brain__pages" aria-label="<?php esc_attr_e( 'Entry pages', 'zorem-ai-agent-connector' ); ?>">
			<span class="zorem-brain__pages-info">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: first entry shown, 2: last entry shown, 3: number of entries. */
						__( 'Showing %1$s–%2$s of %3$s', 'zorem-ai-agent-connector' ),
						number_format_i18n( $zorem_agent_brain_first ),
						number_format_i18n( $zorem_agent_brain_last ),
						number_format_i18n( $zorem_agent_brain_total )
					)
				);
				?>
			</span>
			<span class="zorem-brain__pages-list">
				<?php if ( $zorem_agent_brain_current > 1 ) : ?>
					<a class="zui-page-btn" href="<?php echo esc_url( add_query_arg( 'paged', $zorem_agent_brain_current - 1 ) ); ?>" rel="prev"><?php esc_html_e( 'Previous', 'zorem-ai-agent-connector' ); ?></a>
				<?php else : ?>
					<span class="zui-page-btn" aria-disabled="true"><?php esc_html_e( 'Previous', 'zorem-ai-agent-connector' ); ?></span>
				<?php endif; ?>

				<?php foreach ( $zorem_agent_brain_numbers as $zorem_agent_brain_n ) : ?>
					<?php if ( 0 === $zorem_agent_brain_n ) : ?>
						<span class="zui-page-gap" aria-hidden="true">&hellip;</span>
					<?php elseif ( $zorem_agent_brain_n === $zorem_agent_brain_current ) : ?>
						<span class="zui-page-btn is-active" aria-current="page"><?php echo esc_html( number_format_i18n( $zorem_agent_brain_n ) ); ?></span>
					<?php else : ?>
						<a class="zui-page-btn" href="<?php echo esc_url( add_query_arg( 'paged', $zorem_agent_brain_n ) ); ?>">
							<?php
							echo '<span class="screen-reader-text">' . esc_html__( 'Page', 'zorem-ai-agent-connector' ) . ' </span>' . esc_html( number_format_i18n( $zorem_agent_brain_n ) );
							?>
						</a>
					<?php endif; ?>
				<?php endforeach; ?>

				<?php if ( $zorem_agent_brain_current < $zorem_agent_brain_pages ) : ?>
					<a class="zui-page-btn" href="<?php echo esc_url( add_query_arg( 'paged', $zorem_agent_brain_current + 1 ) ); ?>" rel="next"><?php esc_html_e( 'Next', 'zorem-ai-agent-connector' ); ?></a>
				<?php else : ?>
					<span class="zui-page-btn" aria-disabled="true"><?php esc_html_e( 'Next', 'zorem-ai-agent-connector' ); ?></span>
				<?php endif; ?>
			</span>
		</nav>
	<?php endif; ?>
</section>
