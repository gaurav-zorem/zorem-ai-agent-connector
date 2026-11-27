<?php
/**
 * Website Brain: overview — what your agents know.
 *
 * Ported from AI Agent OS (legacy)'s Website Brain screen: the store's policies and
 * the page that holds each, the pages chosen as knowledge, and what has been
 * read from each plugin, in words a merchant would use.
 *
 * Laid out for the full width of the screen: the figures first, then the two
 * things a merchant sets (policies and pages) side by side, then what was read
 * from each plugin, and the store's details last.
 *
 * @package ZoremAgentBrain
 *
 * @var array<string, mixed> $zorem_agent_brain View data: `status`, `summary`, `store`, `pages`.
 */

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Admin\ScreenMount;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

$zorem_agent_brain_status     = $zorem_agent_brain['status'];
$zorem_agent_brain_summary    = $zorem_agent_brain['summary'];
$zorem_agent_brain_shop       = $zorem_agent_brain['store'];
$zorem_agent_brain_pages      = $zorem_agent_brain['pages'];
$zorem_agent_brain_store      = $zorem_agent_brain_status['store'];
$zorem_agent_brain_by_source  = (array) $zorem_agent_brain_status['by_source'];
$zorem_agent_brain_readable   = (int) $zorem_agent_brain_summary['readable'];
$zorem_agent_brain_policy_ids = array_map( 'intval', wp_list_pluck( array_filter( $zorem_agent_brain_pages['available'], static fn ( array $page ): bool => 'page' === $page['type'] ), 'id' ) );
$zorem_agent_brain_selected   = array_map( 'intval', (array) $zorem_agent_brain_pages['selected'] );
$zorem_agent_brain_listed     = array_map( 'intval', wp_list_pluck( $zorem_agent_brain_pages['available'], 'id' ) );

$zorem_agent_brain_tiles = array(
	array( $zorem_agent_brain_readable, __( 'Items agents can answer from', 'zorem-ai-agent-connector' ), 'is-primary' ),
	array( count( $zorem_agent_brain_summary['plugins'] ), __( 'Plugins read', 'zorem-ai-agent-connector' ), '' ),
	array( (int) ( $zorem_agent_brain_by_source['wp_content'] ?? 0 ), __( 'Your pages', 'zorem-ai-agent-connector' ), '' ),
	array( (int) ( $zorem_agent_brain_by_source['remote_curated'] ?? 0 ), __( 'Bundled documentation', 'zorem-ai-agent-connector' ), '' ),
	array( (int) ( $zorem_agent_brain_by_source['store_settings'] ?? 0 ), __( 'Store settings', 'zorem-ai-agent-connector' ), '' ),
	array( (int) $zorem_agent_brain_status['totals']['tokens'], __( 'Approximate size, in tokens', 'zorem-ai-agent-connector' ), '' ),
);
/*
 * Items shown before "Show all" (12 fills rows of 2, 3 or 4): a store that chose many pages (78 on one live
 * store) would otherwise push everything below off the screen.
 */
$zorem_agent_brain_shown = 12;

/**
 * One item of the store's own words.
 *
 * @param array<string, mixed> $item Item: title, summary, source.
 */
$zorem_agent_brain_word = static function ( array $item ): void {
	if ( KnowledgeEntry::SOURCE_STORE_SETTINGS === $item['source'] ) {
		$from = __( 'WooCommerce settings', 'zorem-ai-agent-connector' );
	} elseif ( KnowledgeEntry::SOURCE_MANUAL === $item['source'] ) {
		$from = __( 'Written by hand', 'zorem-ai-agent-connector' );
	} else {
		$from = __( 'Page on your site', 'zorem-ai-agent-connector' );
	}
	?>
	<li class="zorem-brain__item">
		<div class="zorem-brain__itemhead">
			<strong><?php echo esc_html( (string) $item['title'] ); ?></strong>
			<span class="zorem-brain__from"><?php echo esc_html( $from ); ?></span>
		</div>
		<?php if ( '' !== (string) $item['summary'] ) : ?>
			<p class="zorem-brain__itemtext"><?php echo esc_html( (string) $item['summary'] ); ?></p>
		<?php endif; ?>
	</li>
	<?php
};

/*
 * The plugins using this Brain, by name: the registry keeps an internal id
 * ("ai-agent-os"), which means nothing to a merchant.
 */
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$zorem_agent_brain_installed  = get_plugins();
$zorem_agent_brain_host_names = array();

foreach ( \ZoremAgentBrain\V1\Brain::hosts()->all() as $zorem_agent_brain_host_id => $zorem_agent_brain_host ) {
	$zorem_agent_brain_basename     = is_array( $zorem_agent_brain_host ) ? (string) ( $zorem_agent_brain_host['basename'] ?? '' ) : '';
	$zorem_agent_brain_host_names[] = (string) ( $zorem_agent_brain_installed[ $zorem_agent_brain_basename ]['Name'] ?? $zorem_agent_brain_host_id );
}
?>
<section class="zui-section zorem-brain__overview">
	<?php if ( 'ok' !== $zorem_agent_brain_store['state'] ) : ?>
		<div class="zui-notice zui-notice--error" role="alert">
			<div class="zui-notice__body">
				<strong class="zui-notice__title"><?php esc_html_e( 'The Website Brain store is incomplete', 'zorem-ai-agent-connector' ); ?></strong>
				<p class="zui-notice__text">
					<?php
					/* translators: %s: comma-separated table names. */
					echo esc_html( sprintf( __( 'Missing tables: %s. Deactivate and reactivate the plugin; if that does not help, your database user may lack permission to create tables.', 'zorem-ai-agent-connector' ), implode( ', ', (array) $zorem_agent_brain_store['missing'] ) ) );
					?>
				</p>
			</div>
		</div>
	<?php endif; ?>

	<?php /* ---- What your agents know: the headline and the figures. ---- */ ?>
	<div class="zui-card zorem-brain__hero">
		<div class="zorem-brain__head">
			<div class="zorem-brain__headtext">
				<h2 class="zorem-brain__herotitle"><?php esc_html_e( 'What your agents know', 'zorem-ai-agent-connector' ); ?></h2>
				<p class="zorem-brain__headlede">
					<?php
					/* translators: %s: number of knowledge items. */
					echo esc_html( sprintf( _n( 'Your agents can answer from %s item of written knowledge, read from your store.', 'Your agents can answer from %s items of written knowledge, read from your store.', $zorem_agent_brain_readable, 'zorem-ai-agent-connector' ), number_format_i18n( $zorem_agent_brain_readable ) ) );
					?>
				</p>
				<?php if ( $zorem_agent_brain_summary['superseded'] > 0 ) : ?>
					<p class="zorem-brain__meta">
						<?php
						/* translators: %s: number of superseded entries. */
						echo esc_html( sprintf( _n( '%s further entry has been replaced by better knowledge and is no longer read.', '%s further entries have been replaced by better knowledge and are no longer read.', (int) $zorem_agent_brain_summary['superseded'], 'zorem-ai-agent-connector' ), number_format_i18n( (int) $zorem_agent_brain_summary['superseded'] ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( ! $zorem_agent_brain_summary['complete'] ) : ?>
					<p class="zorem-brain__meta">
						<?php
						/* translators: %s: number of entries counted. */
						echo esc_html( sprintf( __( 'Counted from the first %s entries; the Brain holds more, so the figures here are a lower bound.', 'zorem-ai-agent-connector' ), number_format_i18n( \ZoremAgentBrain\V1\Admin\KnowledgeSummary::LIMIT ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
			<button type="button" class="zui-btn-secondary zorem-brain__rebuild" data-zorem-brain-action data-route="sources/rebuild" data-reload="1"
				data-confirm="<?php esc_attr_e( 'Read your plugins, store settings, bundled documentation and chosen pages again? Plugins are re-read in the background over the next few minutes.', 'zorem-ai-agent-connector' ); ?>">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<span><?php esc_html_e( 'Read everything again', 'zorem-ai-agent-connector' ); ?></span>
			</button>
		</div>

		<div class="zorem-brain__tiles">
			<?php foreach ( $zorem_agent_brain_tiles as $zorem_agent_brain_tile ) : ?>
				<div class="zorem-brain__tile <?php echo esc_attr( $zorem_agent_brain_tile[2] ); ?>">
					<span class="zorem-brain__tilenum"><?php echo esc_html( number_format_i18n( (int) $zorem_agent_brain_tile[0] ) ); ?></span>
					<span class="zorem-brain__tilelabel"><?php echo esc_html( $zorem_agent_brain_tile[1] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<?php /* ---- The shared store, in one line of facts. ---- */ ?>
	<div class="zui-card zorem-brain__card">
		<div class="zorem-brain__facts-strip">
			<div class="zorem-brain__factcell">
				<span class="zorem-brain__factlabel"><?php esc_html_e( 'Entries stored', 'zorem-ai-agent-connector' ); ?></span>
				<span class="zorem-brain__factvalue"><?php echo esc_html( number_format_i18n( $zorem_agent_brain_status['totals']['entries'] ) ); ?></span>
			</div>
			<div class="zorem-brain__factcell">
				<span class="zorem-brain__factlabel"><?php esc_html_e( 'Plugins using this Brain', 'zorem-ai-agent-connector' ); ?></span>
				<span class="zorem-brain__factvalue"><?php echo esc_html( implode( ', ', $zorem_agent_brain_host_names ) ); ?></span>
			</div>
			<div class="zorem-brain__factcell">
				<span class="zorem-brain__factlabel"><?php esc_html_e( 'Plugin reading', 'zorem-ai-agent-connector' ); ?></span>
				<span class="zorem-brain__factvalue">
					<?php
					$zorem_agent_brain_queue = $zorem_agent_brain_status['extraction'];
					/* translators: 1: done, 2: queued, 3: partial, 4: failed. */
					echo esc_html( sprintf( __( '%1$d done, %2$d waiting, %3$d partial, %4$d failed', 'zorem-ai-agent-connector' ), $zorem_agent_brain_queue['done'] ?? 0, ( $zorem_agent_brain_queue['queued'] ?? 0 ) + ( $zorem_agent_brain_queue['running'] ?? 0 ), $zorem_agent_brain_queue['partial'] ?? 0, $zorem_agent_brain_queue['failed'] ?? 0 ) );
					?>
				</span>
			</div>
			<div class="zorem-brain__factcell">
				<span class="zorem-brain__factlabel"><?php esc_html_e( 'Website Brain Kit', 'zorem-ai-agent-connector' ); ?></span>
				<span class="zorem-brain__factvalue"><?php echo esc_html( (string) $zorem_agent_brain_status['kit'] ); ?></span>
			</div>
		</div>
	</div>

	<div class="zorem-brain__columns">

		<?php /* ---- Store policies. ---- */ ?>
		<div class="zui-card zorem-brain__card">
			<div class="zui-card__head zorem-brain__cardhead">
				<div>
					<h2 class="zui-card__title"><?php esc_html_e( 'Store policies', 'zorem-ai-agent-connector' ); ?></h2>
					<p class="zorem-brain__cardsub"><?php esc_html_e( 'What an agent answers from when a shopper asks about returns, delivery or payment. Saved to your WooCommerce settings, so checkout links to the same page.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
			</div>
			<div class="zui-card__body">
				<?php if ( ! $zorem_agent_brain_shop['available'] ) : ?>
					<p class="zorem-brain__lede"><?php esc_html_e( 'WooCommerce is not active, so there are no store policies to choose.', 'zorem-ai-agent-connector' ); ?></p>
				<?php else : ?>
					<div class="zorem-brain__policies">
						<?php foreach ( $zorem_agent_brain_shop['policies'] as $zorem_agent_brain_purpose => $zorem_agent_brain_state ) : ?>
							<?php
							$zorem_agent_brain_pid   = (int) $zorem_agent_brain_state['id'];
							$zorem_agent_brain_label = WooCommerceSource::purpose_label( (string) $zorem_agent_brain_purpose );
							$zorem_agent_brain_read  = '' === $zorem_agent_brain_state['reason'];
							?>
							<div class="zorem-brain__policy-row">
								<div class="zorem-brain__policy-name">
									<strong><?php echo esc_html( $zorem_agent_brain_label ); ?></strong>
									<span class="zui-badge <?php echo $zorem_agent_brain_read ? 'zui-badge--success' : 'zui-badge--neutral'; ?>" title="<?php esc_attr_e( 'In the Brain', 'zorem-ai-agent-connector' ); ?>">
										<?php echo $zorem_agent_brain_read ? esc_html__( 'Read', 'zorem-ai-agent-connector' ) : esc_html__( 'Not read', 'zorem-ai-agent-connector' ); ?>
									</span>
								</div>
								<div class="zorem-brain__policy-pick">
									<div class="zui-select-wrap">
										<select class="zui-select zorem-brain__policy" data-zorem-brain-policy="<?php echo esc_attr( (string) $zorem_agent_brain_purpose ); ?>" aria-label="<?php echo esc_attr( $zorem_agent_brain_label ); ?>">
											<option value="0"><?php esc_html_e( '— not set —', 'zorem-ai-agent-connector' ); ?></option>
											<?php foreach ( $zorem_agent_brain_pages['available'] as $zorem_agent_brain_page ) : ?>
												<?php
												if ( 'page' !== $zorem_agent_brain_page['type'] ) {
													continue;
												}
												?>
												<option value="<?php echo esc_attr( (string) $zorem_agent_brain_page['id'] ); ?>" <?php selected( $zorem_agent_brain_pid, (int) $zorem_agent_brain_page['id'] ); ?>><?php echo esc_html( $zorem_agent_brain_page['title'] ); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<?php
									/*
									 * A page pointed at but not published is not in the
									 * picker, which lists published pages only — so it is
									 * named here instead. Showing "not set" for a page the
									 * merchant did set would send them to set it again.
									 */
									?>
									<?php if ( $zorem_agent_brain_pid > 0 && ( ! $zorem_agent_brain_read || ! in_array( $zorem_agent_brain_pid, $zorem_agent_brain_policy_ids, true ) ) ) : ?>
										<p class="zorem-brain__meta zorem-brain__policynote">
											<?php echo esc_html( html_entity_decode( get_the_title( $zorem_agent_brain_pid ), ENT_QUOTES, 'UTF-8' ) ); ?>
											<?php if ( ! $zorem_agent_brain_read ) : ?>
												— <?php echo esc_html( (string) $zorem_agent_brain_state['reason'] ); ?>
												<?php $zorem_agent_brain_edit = (string) get_edit_post_link( $zorem_agent_brain_pid, 'raw' ); ?>
												<?php if ( '' !== $zorem_agent_brain_edit ) : ?>
													<a href="<?php echo esc_url( $zorem_agent_brain_edit ); ?>"><?php esc_html_e( 'Publish it', 'zorem-ai-agent-connector' ); ?></a>
												<?php endif; ?>
											<?php endif; ?>
										</p>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			</div>
		</div>

		<?php /* ---- Add another page. ---- */ ?>
		<div class="zui-card zorem-brain__card" data-zorem-brain-checklist>
			<div class="zui-card__head zorem-brain__cardhead">
				<div>
					<h2 class="zui-card__title"><?php esc_html_e( 'Add another page', 'zorem-ai-agent-connector' ); ?></h2>
					<p class="zorem-brain__cardsub"><?php esc_html_e( 'An FAQ, a shipping guide, a size chart — any page can become knowledge. Nothing is read until you tick it. An agent quotes the text as something a person wrote, so instructions inside a page are repeated, never obeyed.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<button type="button" class="zui-btn-primary" data-zorem-brain-save-pages disabled><span><?php esc_html_e( 'Save selection', 'zorem-ai-agent-connector' ); ?></span></button>
			</div>
			<div class="zui-card__body">
				<label class="zorem-brain__allposts">
					<input type="checkbox" data-zorem-brain-all-posts <?php checked( (bool) $zorem_agent_brain_pages['all_posts'] ); ?>>
					<span>
						<strong><?php esc_html_e( 'Read every published post', 'zorem-ai-agent-connector' ); ?></strong>
						<span class="zorem-brain__meta">
							<?php
							$zorem_agent_brain_count = (int) $zorem_agent_brain_pages['post_count'];
							$zorem_agent_brain_bound = (int) $zorem_agent_brain_pages['post_bound'];

							if ( $zorem_agent_brain_count > $zorem_agent_brain_bound ) {
								/* translators: 1: number of posts read, 2: number of published posts. */
								echo esc_html( sprintf( __( 'Blog posts become knowledge as they are published, without being ticked here. The newest %1$s of your %2$s posts are read — beyond that, tick the ones that matter.', 'zorem-ai-agent-connector' ), number_format_i18n( $zorem_agent_brain_bound ), number_format_i18n( $zorem_agent_brain_count ) ) );
							} else {
								/* translators: %s: number of published posts. */
								echo esc_html( sprintf( _n( 'Blog posts become knowledge as they are published, without being ticked here. You have %s published post.', 'Blog posts become knowledge as they are published, without being ticked here. You have %s published posts.', $zorem_agent_brain_count, 'zorem-ai-agent-connector' ), number_format_i18n( $zorem_agent_brain_count ) ) );
							}
							?>
						</span>
					</span>
				</label>

				<?php if ( array() === $zorem_agent_brain_pages['available'] ) : ?>
					<p class="zorem-brain__meta"><?php esc_html_e( 'No published pages or posts to choose from yet. Publish a returns or shipping page and it will appear here.', 'zorem-ai-agent-connector' ); ?></p>
				<?php else : ?>
					<?php
					/*
					 * Chosen pages that are not in the list — beyond the first 200 —
					 * stay chosen. The save reads every tick in the list, hidden by a
					 * search or not, plus these (Rule 19: a filter changes what you
					 * see, never what is).
					 */
					?>
					<?php foreach ( array_diff( $zorem_agent_brain_selected, $zorem_agent_brain_listed ) as $zorem_agent_brain_extra ) : ?>
						<input type="hidden" value="<?php echo esc_attr( (string) $zorem_agent_brain_extra ); ?>" data-zorem-brain-pick-extra>
					<?php endforeach; ?>

					<div class="zorem-brain__checkbar">
						<input type="search" class="zui-input" data-zorem-brain-check-search placeholder="<?php esc_attr_e( 'Search pages…', 'zorem-ai-agent-connector' ); ?>" aria-label="<?php esc_attr_e( 'Search pages', 'zorem-ai-agent-connector' ); ?>">
						<span class="zorem-brain__count" data-zorem-brain-check-count
							data-template="<?php /* translators: 1: number selected, 2: number available. */ esc_attr_e( '%1$d of %2$d selected', 'zorem-ai-agent-connector' ); ?>">
							<?php
							/* translators: 1: number selected, 2: number available. */
							echo esc_html( sprintf( __( '%1$d of %2$d selected', 'zorem-ai-agent-connector' ), count( array_intersect( $zorem_agent_brain_selected, $zorem_agent_brain_listed ) ), count( $zorem_agent_brain_listed ) ) );
							?>
						</span>
						<button type="button" class="zui-btn-ghost zorem-brain__showselected" data-zorem-brain-check-selected
							data-label-all="<?php esc_attr_e( 'Show all', 'zorem-ai-agent-connector' ); ?>"
							data-label-selected="<?php esc_attr_e( 'Show selected only', 'zorem-ai-agent-connector' ); ?>"><?php esc_html_e( 'Show selected only', 'zorem-ai-agent-connector' ); ?></button>
					</div>

					<div class="zorem-brain__checklist">
						<?php foreach ( $zorem_agent_brain_pages['available'] as $zorem_agent_brain_page ) : ?>
							<?php $zorem_agent_brain_search = mb_strtolower( $zorem_agent_brain_page['title'] . ' ' . $zorem_agent_brain_page['type'] ); ?>
							<label class="zorem-brain__checkrow" data-zorem-brain-check-row data-search="<?php echo esc_attr( $zorem_agent_brain_search ); ?>">
								<input type="checkbox" value="<?php echo esc_attr( (string) $zorem_agent_brain_page['id'] ); ?>" data-zorem-brain-pick <?php checked( in_array( (int) $zorem_agent_brain_page['id'], $zorem_agent_brain_selected, true ) ); ?>>
								<span class="zorem-brain__checktitle"><?php echo esc_html( $zorem_agent_brain_page['title'] ); ?></span>
								<span class="zorem-brain__type"><?php echo esc_html( $zorem_agent_brain_page['type'] ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>

					<p class="zorem-brain__meta" data-zorem-brain-check-empty hidden><?php esc_html_e( 'No page matches that search.', 'zorem-ai-agent-connector' ); ?></p>

					<?php if ( $zorem_agent_brain_pages['truncated'] ) : ?>
						<p class="zorem-brain__meta">
							<?php
							/* translators: %s: number of pages listed. */
							echo esc_html( sprintf( __( 'Only the first %s published pages and posts are listed. If the one you want is missing, that is why — search only filters what is on this list.', 'zorem-ai-agent-connector' ), number_format_i18n( \ZoremAgentBrain\V1\Admin\Screen::PAGE_LIST_LIMIT ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php /* ---- In your own words: a card of its own, so a store with many pages keeps the columns above even. ---- */ ?>
	<?php if ( array() !== $zorem_agent_brain_summary['store'] ) : ?>
		<?php
		$zorem_agent_brain_words = (array) $zorem_agent_brain_summary['store'];
		$zorem_agent_brain_first = array_slice( $zorem_agent_brain_words, 0, $zorem_agent_brain_shown );
		$zorem_agent_brain_rest  = array_slice( $zorem_agent_brain_words, $zorem_agent_brain_shown );
		?>
		<div class="zui-card zorem-brain__card">
			<div class="zui-card__head zorem-brain__cardhead">
				<div>
					<h2 class="zui-card__title"><?php esc_html_e( 'In your own words', 'zorem-ai-agent-connector' ); ?></h2>
					<p class="zorem-brain__cardsub"><?php esc_html_e( 'Your pages and store settings, as your agents read them.', 'zorem-ai-agent-connector' ); ?></p>
				</div>
				<span class="zorem-brain__pill">
					<?php
					/* translators: %s: number of items. */
					echo esc_html( sprintf( _n( '%s item', '%s items', count( $zorem_agent_brain_words ), 'zorem-ai-agent-connector' ), number_format_i18n( count( $zorem_agent_brain_words ) ) ) );
					?>
				</span>
			</div>
			<div class="zui-card__body">
				<ul class="zorem-brain__items zorem-brain__items--grid">
					<?php foreach ( $zorem_agent_brain_first as $zorem_agent_brain_item ) : ?>
						<?php $zorem_agent_brain_word( $zorem_agent_brain_item ); ?>
					<?php endforeach; ?>
				</ul>
				<?php if ( array() !== $zorem_agent_brain_rest ) : ?>
					<details class="zorem-brain__more">
						<summary>
							<span class="zorem-brain__more-show">
								<?php
								/* translators: %s: total number of items. */
								echo esc_html( sprintf( __( 'Show all %s', 'zorem-ai-agent-connector' ), number_format_i18n( count( $zorem_agent_brain_words ) ) ) );
								?>
							</span>
							<span class="zorem-brain__more-hide"><?php esc_html_e( 'Show fewer', 'zorem-ai-agent-connector' ); ?></span>
						</summary>
						<ul class="zorem-brain__items zorem-brain__items--grid">
							<?php foreach ( $zorem_agent_brain_rest as $zorem_agent_brain_item ) : ?>
								<?php $zorem_agent_brain_word( $zorem_agent_brain_item ); ?>
							<?php endforeach; ?>
						</ul>
					</details>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>

	<?php /* ---- Your plugins. ---- */ ?>
	<div class="zui-card zorem-brain__card">
		<div class="zui-card__head zorem-brain__cardhead">
			<div>
				<h2 class="zui-card__title"><?php esc_html_e( 'Your plugins', 'zorem-ai-agent-connector' ); ?></h2>
				<p class="zorem-brain__cardsub"><?php esc_html_e( 'What has been read from each plugin, and what your agents can therefore answer about it.', 'zorem-ai-agent-connector' ); ?></p>
			</div>
			<span class="zorem-brain__pill">
				<?php
				/* translators: %s: number of plugins. */
				echo esc_html( sprintf( _n( '%s plugin read', '%s plugins read', count( $zorem_agent_brain_summary['plugins'] ), 'zorem-ai-agent-connector' ), number_format_i18n( count( $zorem_agent_brain_summary['plugins'] ) ) ) );
				?>
			</span>
		</div>
		<div class="zui-card__body zorem-brain__flush">
			<?php if ( array() === $zorem_agent_brain_summary['plugins'] ) : ?>
				<p class="zorem-brain__meta zorem-brain__pad"><?php esc_html_e( 'No plugin has been read yet.', 'zorem-ai-agent-connector' ); ?></p>
			<?php else : ?>
				<div class="zui-table-scroll">
					<table class="zui-table zorem-brain__table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Plugin', 'zorem-ai-agent-connector' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Your agents know', 'zorem-ai-agent-connector' ); ?></th>
								<th scope="col" class="zorem-brain__end"><?php esc_html_e( 'Items', 'zorem-ai-agent-connector' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $zorem_agent_brain_summary['plugins'] as $zorem_agent_brain_row ) : ?>
								<tr>
									<td class="zorem-brain__plugin">
										<strong><?php echo esc_html( (string) $zorem_agent_brain_row['name'] ); ?></strong>
										<span class="zorem-brain__tags">
											<?php if ( '' !== (string) $zorem_agent_brain_row['version'] ) : ?>
												<code class="zorem-brain__version"><?php echo esc_html( (string) $zorem_agent_brain_row['version'] ); ?></code>
											<?php endif; ?>
											<?php if ( $zorem_agent_brain_row['curated'] ) : ?>
												<span class="zui-badge zui-badge--success" title="<?php esc_attr_e( 'Written by hand and shipped with this plugin, rather than read from the plugin’s own code.', 'zorem-ai-agent-connector' ); ?>"><?php esc_html_e( 'Curated', 'zorem-ai-agent-connector' ); ?></span>
											<?php endif; ?>
										</span>
									</td>
									<td>
										<?php if ( '' !== (string) $zorem_agent_brain_row['knows'] ) : ?>
											<?php echo esc_html( (string) $zorem_agent_brain_row['knows'] ); ?>
										<?php else : ?>
											<span class="zorem-brain__meta"><?php esc_html_e( 'Technical detail only', 'zorem-ai-agent-connector' ); ?></span>
										<?php endif; ?>
										<?php if ( $zorem_agent_brain_row['truncated'] ) : ?>
											<span class="zorem-brain__partial"><?php esc_html_e( 'Only read in part, so anything it does not mention may simply not have been reached.', 'zorem-ai-agent-connector' ); ?></span>
										<?php endif; ?>
									</td>
									<td class="zorem-brain__end zorem-brain__num"><?php echo esc_html( number_format_i18n( (int) $zorem_agent_brain_row['items'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<?php if ( $zorem_agent_brain_summary['technical'] > 0 ) : ?>
				<p class="zorem-brain__meta zorem-brain__ruled zorem-brain__pad">
					<?php
					/* translators: %s: number of internal-tier items. */
					echo esc_html( sprintf( _n( '%s of these items is technical detail — hooks, REST routes and post types. Only agents you mark as internal can read it; a shopping assistant never sees it.', '%s of these items are technical detail — hooks, REST routes and post types. Only agents you mark as internal can read it; a shopping assistant never sees it.', (int) $zorem_agent_brain_summary['technical'], 'zorem-ai-agent-connector' ), number_format_i18n( (int) $zorem_agent_brain_summary['technical'] ) ) );
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

</section>
