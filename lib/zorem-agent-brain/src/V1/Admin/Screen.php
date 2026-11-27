<?php
/**
 * The Website Brain admin screen.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Admin;

use ZoremAgentBrain\V1\Ai\ConsentLedger;
use ZoremAgentBrain\V1\Ai\CredentialStore;
use ZoremAgentBrain\V1\Ai\ModelChoice;
use ZoremAgentBrain\V1\Ai\ModelGateway;
use ZoremAgentBrain\V1\Ai\ProviderResolver;
use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Rest\KnowledgeController;
use ZoremAgentBrain\V1\Rest\SourcesController;
use ZoremAgentBrain\V1\Scope\SectionTiers;
use ZoremAgentBrain\V1\Settings;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Sources\WpContentSource;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered tabs; every change goes through the REST API (assets/admin.js).
 *
 * Data comes from the same services and controllers the REST API uses, so
 * the screen and the API cannot describe the store differently. Every value
 * printed is escaped at output in the views.
 */
final class Screen {

	/**
	 * Most pages listed on the overview's pickers. Search filters these in the browser.
	 */
	public const PAGE_LIST_LIMIT = 200;

	/**
	 * Tabs: slug => label.
	 *
	 * @return array<string, string>
	 */
	public static function tabs(): array {
		$tabs = array(
			'overview' => __( 'Overview', 'zorem-ai-agent-connector' ),
			'sources'  => __( 'Sources', 'zorem-ai-agent-connector' ),
			'entries'  => __( 'Entries', 'zorem-ai-agent-connector' ),
			'ai'       => __( 'AI provider', 'zorem-ai-agent-connector' ),
		);

		if ( ! self::shows_ai_tab() ) {
			unset( $tabs['ai'] );
		}

		return $tabs;
	}

	/**
	 * Whether the AI provider tab is shown: only when AI Shopping Assistant is
	 * running and does not show the settings on its own screen.
	 *
	 * The key is for the assistants Experiences runs. With AI Agent OS alone
	 * the agents bring their own AI over MCP and the site needs no key, so the
	 * tab is one more thing to set up for nothing. A hidden tab falls back to
	 * the overview when asked for; the AI routes are unchanged, so a key
	 * already stored keeps working.
	 */
	public static function shows_ai_tab(): bool {
		return isset( Brain::screens()->mounts()['ai-shopping-assistant'] ) && '' === Brain::screens()->ai_host_url();
	}

	/**
	 * Whether the AI provider settings are offered anywhere: this tab or a host's screen.
	 */
	public static function offers_ai(): bool {
		return self::shows_ai_tab() || '' !== Brain::screens()->ai_host_url();
	}

	/**
	 * Print the AI provider settings on a host's screen.
	 *
	 * The same view as the tab, in a wrapper the kit's styles know. The host
	 * loads the assets with `ScreenMount::enqueue_assets()` and the ZUI icons.
	 */
	public static function render_ai_panel(): void {
		if ( ! current_user_can( Brain::CAPABILITY ) ) {
			return;
		}

		echo '<div class="zorem-brain--hosted">';
		self::view( 'ai', ( new self() )->data_ai() );
		echo '</div>';
	}

	/**
	 * Render a tab; unknown tabs show the overview.
	 *
	 * @param string $tab Requested tab.
	 */
	public function render( string $tab ): void {
		$tabs = self::tabs();
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		$data = array(
			'tab'  => $tab,
			'tabs' => $tabs,
		);

		$data = array_merge( $data, $this->{ 'data_' . $tab }() );

		self::view( 'layout', $data );
	}

	/**
	 * Include a view with its data. Views read `$zorem_agent_brain` only.
	 *
	 * @param string               $name              View name.
	 * @param array<string, mixed> $zorem_agent_brain Data.
	 */
	public static function view( string $name, array $zorem_agent_brain ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Read by the included view.
		$file = __DIR__ . '/views/' . $name . '.php';

		if ( 1 === preg_match( '/^[a-z-]+$/', $name ) && is_readable( $file ) ) {
			include $file;
		}
	}

	/**
	 * Print a ZUI icon; nothing when the host's ZUI copy has no icon helper.
	 *
	 * @param string $name Icon name.
	 */
	public static function icon( string $name ): void {
		if ( function_exists( 'Zorem\UI\icon' ) ) {
			\Zorem\UI\icon( $name );
		}
	}

	/**
	 * Print a row's action menu: the ZUI three-dot menu, with the items given.
	 *
	 * Each item is an array of `label`, `icon`, `danger` (bool) and `attrs`
	 * (attribute => value, escaped here). The items keep the data attributes the
	 * kit's JavaScript listens for, so a menu item does what its button did.
	 *
	 * @param array<int, array{label: string, icon: string, danger?: bool, attrs: array<string, string>}> $items Menu items.
	 */
	public static function row_menu( array $items ): void {
		?>
		<div class="zui-actions">
			<button type="button" class="zui-actions-toggle" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'Actions', 'zorem-ai-agent-connector' ); ?>"><?php self::icon( 'more-vertical' ); ?></button>
			<div class="zui-actions-menu" hidden role="menu">
				<?php foreach ( $items as $item ) : ?>
					<button type="button" class="zui-actions-item<?php echo empty( $item['danger'] ) ? '' : ' zui-actions-item--danger'; ?>" role="menuitem"
						<?php
						foreach ( $item['attrs'] as $attr => $value ) {
							echo ' ' . esc_attr( $attr ) . '="' . esc_attr( $value ) . '"';
						}
						?>
					><?php self::icon( $item['icon'] ); ?><span><?php echo esc_html( $item['label'] ); ?></span></button>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Print a UTC database time in the site's timezone.
	 *
	 * @param string|null $utc MySQL datetime in UTC.
	 */
	public static function local_time( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return __( 'Never', 'zorem-ai-agent-connector' );
		}

		$time = strtotime( $utc . ' UTC' );

		return false === $time ? $utc : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/**
	 * Overview data.
	 *
	 * @return array<string, mixed>
	 */
	private function data_overview(): array {
		$pages = Brain::get( WpContentSource::class );
		$store = Brain::get( WooCommerceSource::class );

		/*
		 * Published pages for the policy pickers and the checklist, bounded at
		 * 200 and listed whole: the point of the screen is one view of
		 * everything, and search filters the rows in the browser. Only what the
		 * routes would accept is offered — published, no password.
		 */
		$query = new \WP_Query(
			array(
				'post_type'      => WpContentSource::selectable_types(),
				'post_status'    => 'publish',
				'posts_per_page' => self::PAGE_LIST_LIMIT + 1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'has_password'   => false,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$ids       = array_map( 'intval', $query->posts );
		$truncated = count( $ids ) > self::PAGE_LIST_LIMIT;
		$ids       = array_slice( $ids, 0, self::PAGE_LIST_LIMIT );
		$available = array();

		foreach ( $ids as $id ) {
			$available[] = array(
				'id'    => $id,
				'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ),
				'type'  => (string) get_post_type( $id ),
			);
		}

		return array(
			'status'  => ( new KnowledgeController() )->status()->get_data(),
			'summary' => Brain::get( KnowledgeSummary::class )->build(),
			'store'   => array(
				'available' => $store->is_available(),
				'policies'  => $store->policy_page_status(),
			),
			'pages'   => array(
				'available'  => $available,
				'truncated'  => $truncated,
				'selected'   => $pages->selected(),
				'all_posts'  => $pages->all_posts(),
				'post_count' => $pages->published_post_count(),
				'post_bound' => WpContentSource::MAX_ALL_POSTS,
			),
		);
	}

	/**
	 * Sources data.
	 *
	 * @return array<string, mixed>
	 */
	private function data_sources(): array {
		return array(
			'sources'  => ( new SourcesController() )->sources()->get_data(),
			'settings' => Settings::all(),
		);
	}

	/**
	 * Entries data: one page, filtered from the query string.
	 *
	 * @return array<string, mixed>
	 */
	private function data_entries(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$filters = array(
			'source'   => isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : '',
			'section'  => isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '',
			'trust'    => isset( $_GET['trust'] ) ? sanitize_key( wp_unslash( $_GET['trust'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? mb_substr( sanitize_text_field( wp_unslash( $_GET['s'] ) ), 0, 200 ) : '',
			'inactive' => isset( $_GET['inactive'] ) && '1' === sanitize_key( wp_unslash( $_GET['inactive'] ) ),
			'paged'    => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'source', in_array( $filters['source'], KnowledgeEntry::source_types(), true ) ? $filters['source'] : '' );
		$request->set_param( 'section', in_array( $filters['section'], SectionTiers::all_sections(), true ) ? $filters['section'] : '' );
		$request->set_param( 'trust', in_array( $filters['trust'], array( KnowledgeEntry::TRUST_APPROVED, KnowledgeEntry::TRUST_DRAFT ), true ) ? $filters['trust'] : '' );
		$request->set_param( 'plugin', '' );
		$request->set_param( 'search', $filters['search'] );
		$request->set_param( 'inactive', $filters['inactive'] );
		$request->set_param( 'page', $filters['paged'] );
		$request->set_param( 'per_page', 25 );

		return array(
			'filters'  => $filters,
			'entries'  => ( new KnowledgeController() )->entries( $request )->get_data(),
			'sections' => SectionTiers::all_sections(),
			'kinds'    => KnowledgeEntry::source_types(),
		);
	}

	/**
	 * AI provider data.
	 *
	 * @return array<string, mixed>
	 */
	private function data_ai(): array {
		// The lease inside availability() hands a stored key to the AI client, so the model list can be asked for after it.
		$availability = Brain::get( ModelGateway::class )->availability( 'enrichment' );
		$provider     = (string) ( $availability['provider'] ?? '' );
		$reachable    = '' !== $provider && ! empty( $availability['configured'] ) && ! empty( $availability['client_ready'] );

		return array(
			'availability' => $availability,
			'model'        => array(
				'provider'  => $provider,
				'reachable' => $reachable,
				'chosen'    => '' !== $provider ? ModelChoice::for_provider( $provider ) : '',
				'models'    => $reachable ? ModelChoice::models( $provider ) : array(),
			),
			'assistants'   => Brain::get( ModelGateway::class )->lease( 'storefront', ModelGateway::AUDIENCE_ADMIN ),
			'consent'      => Brain::get( ConsentLedger::class )->describe(),
			'stored_key'   => Brain::get( CredentialStore::class )->describe(),
			'providers'    => array_combine( ProviderResolver::known_providers(), array_map( array( ProviderResolver::class, 'label' ), ProviderResolver::known_providers() ) ),
		);
	}
}
