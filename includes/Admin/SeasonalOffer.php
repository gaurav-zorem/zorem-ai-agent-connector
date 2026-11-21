<?php
/**
 * The Black Friday offer for AI Agent OS PRO.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Admin;

use AiAgentOs\Core\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * A time-limited Black Friday offer, shown two ways and only between START and END:
 *
 *   1. A full-width admin notice on every admin screen except this plugin's
 *      own, dismissible per user (user meta, so one administrator dismissing
 *      it does not hide it for another).
 *   2. A full-width banner at the top of this plugin's screen, not
 *      dismissible: it is this plugin's own page.
 *
 * Both are built from ZUI's plugin-notice component (`.zui-pnotice`), which
 * is written to render outside `.zui-scope`, so the admin notice needs only
 * that one stylesheet on other screens. Shown only to people who can manage
 * this plugin, never to editors or shop staff. Nothing runs outside the dates.
 */
final class SeasonalOffer {

	/**
	 * Coupon code, as on zorem.com.
	 */
	public const CODE = 'BFCM40';

	/**
	 * First moment of the offer, in the site's timezone.
	 */
	private const START = '2026-11-19 00:00:00';

	/**
	 * Last moment of the offer, in the site's timezone.
	 */
	private const END = '2026-12-01 23:59:59';

	/**
	 * AI Agent OS PRO's price on zorem.com, in dollars: "Buy AI Agent OS - $79",
	 * one site, one year (confirmed by the user, 2026-11-19).
	 */
	private const PRICE = 79;

	/**
	 * The price with 40% off ($47.40), rounded down to whole dollars.
	 */
	private const SALE_PRICE = 47;

	/**
	 * Product page.
	 */
	private const URL = 'https://www.zorem.com/product/ai-agent-os/';

	/**
	 * User meta recording that this user dismissed the notice.
	 */
	public const DISMISSED_META = 'ai_agent_os_offer_bfcm2026_dismissed';

	/**
	 * AJAX action that dismisses it.
	 */
	public const AJAX_ACTION = 'ai_agent_os_dismiss_offer';

	/**
	 * Register hooks, only while the offer runs.
	 */
	public function register(): void {
		if ( ! self::is_running() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'dismiss' ) );
	}

	/**
	 * Whether today falls inside the offer, in the site's timezone.
	 *
	 * @param int|null $now Unix time to test; now when null.
	 */
	public static function is_running( ?int $now = null ): bool {
		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( self::START, $zone ) )->getTimestamp();
		$end   = ( new \DateTimeImmutable( self::END, $zone ) )->getTimestamp();
		$now   = null === $now ? self::now() : $now;

		return $now >= $start && $now <= $end;
	}

	/**
	 * The current time, as the offer sees it.
	 *
	 * Filterable so a test can check both placements before, during and after
	 * the offer without waiting for the calendar.
	 */
	private static function now(): int {
		/**
		 * Filters the time the Black Friday offer compares with its dates.
		 *
		 * @param int $now Unix time.
		 */
		return (int) apply_filters( 'ai_agent_os_offer_now', time() );
	}

	/**
	 * Whether the current screen is this plugin's own.
	 */
	private static function on_own_screen(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.CSRF.NonceVerification -- Read-only: which screen is showing.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return AdminMenu::MENU_SLUG === $page;
	}

	/**
	 * Whether the admin notice should show to the current user on this screen.
	 */
	private function notice_due(): bool {
		return self::is_running()
			&& current_user_can( Capabilities::MANAGE )
			&& ! self::on_own_screen()
			&& ! get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}

	/**
	 * Load the styles (and the dismiss script) where the offer shows.
	 */
	public function enqueue(): void {
		$own = self::on_own_screen() && current_user_can( Capabilities::MANAGE );

		if ( ! $own && ! $this->notice_due() ) {
			return;
		}

		$zui = AI_AGENT_OS_URL . 'assets/zui/css/components/plugin-notice.css';

		wp_enqueue_style( 'zui-plugin-notice', $zui, array(), AI_AGENT_OS_VERSION );
		wp_enqueue_style( 'ai-agent-os-offer', AI_AGENT_OS_URL . 'assets/css/offer.css', array( 'zui-plugin-notice' ), AI_AGENT_OS_VERSION );

		if ( ! $own ) {
			wp_enqueue_script( 'ai-agent-os-offer', AI_AGENT_OS_URL . 'assets/js/offer.js', array(), AI_AGENT_OS_VERSION, true );
			wp_localize_script(
				'ai-agent-os-offer',
				'aiAgentOsOffer',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::AJAX_ACTION,
					'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				)
			);
		}
	}

	/**
	 * Remember that this user dismissed the notice.
	 */
	public function dismiss(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );

		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( null, 403 );
		}

		update_user_meta( get_current_user_id(), self::DISMISSED_META, time() );

		wp_send_json_success();
	}

	/**
	 * Where "Upgrade" leads, tagged with where it was clicked.
	 *
	 * @param string $content Where the link sits.
	 */
	private static function url( string $content ): string {
		return add_query_arg(
			array(
				'utm_source'   => 'wp-admin',
				'utm_medium'   => 'zorem-ai-agent-connector',
				'utm_campaign' => 'black-friday-2026',
				'utm_content'  => sanitize_key( $content ),
			),
			self::URL
		);
	}

	/**
	 * The shared line: code, prices, terms, end date.
	 */
	private static function details(): void {
		?>
		<span class="aaos-offer__detail">
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: coupon code. */
					__( 'Use code %s at checkout', 'zorem-ai-agent-connector' ),
					'<code class="aaos-offer__code">' . esc_html( self::CODE ) . '</code>'
				),
				array( 'code' => array( 'class' => true ) )
			);
			?>
		</span>
		<span class="aaos-offer__sep" aria-hidden="true">·</span>
		<span class="aaos-offer__detail">
			<s class="aaos-offer__was">
				<?php
				/* translators: %d: regular price in US dollars. */
				echo esc_html( sprintf( __( '$%d', 'zorem-ai-agent-connector' ), self::PRICE ) );
				?>
			</s>
			<span aria-hidden="true">→</span>
			<strong class="aaos-offer__now">
				<?php
				/* translators: %d: offer price in US dollars. */
				echo esc_html( sprintf( __( '$%d', 'zorem-ai-agent-connector' ), self::SALE_PRICE ) );
				?>
			</strong>
		</span>
		<span class="aaos-offer__sep" aria-hidden="true">·</span>
		<span class="aaos-offer__detail"><?php esc_html_e( 'New purchases only', 'zorem-ai-agent-connector' ); ?></span>
		<span class="aaos-offer__sep" aria-hidden="true">·</span>
		<span class="aaos-offer__detail">
			<?php
			/* translators: %s: the date the offer ends. */
			echo esc_html( sprintf( __( 'Ends %s', 'zorem-ai-agent-connector' ), wp_date( get_option( 'date_format' ), ( new \DateTimeImmutable( self::END, wp_timezone() ) )->getTimestamp() ) ) );
			?>
		</span>
		<?php
	}

	/**
	 * The upgrade button.
	 *
	 * @param string $content Where it sits, for the link.
	 */
	private static function button( string $content ): void {
		?>
		<a class="zui-pnotice__btn aaos-offer__cta" href="<?php echo esc_url( self::url( $content ) ); ?>" target="_blank" rel="noopener noreferrer">
			<?php
			/* translators: %d: offer price in US dollars. */
			echo esc_html( sprintf( __( 'Upgrade → $%d', 'zorem-ai-agent-connector' ), self::SALE_PRICE ) );
			?>
		</a>
		<?php
	}

	/**
	 * The admin notice on every other admin screen: full width, dismissible per user.
	 */
	public function notice(): void {
		if ( ! $this->notice_due() ) {
			return;
		}

		require_once AI_AGENT_OS_PATH . 'assets/zui/icons.php';
		?>
		<div class="zui-pnotice aaos-offer aaos-offer--notice" role="status" data-aaos-offer>
			<span class="zui-pnotice__avatar aaos-offer__avatar" aria-hidden="true"><?php \Zorem\UI\icon( 'zap' ); ?></span>
			<div class="zui-pnotice__body">
				<strong class="zui-pnotice__title"><?php esc_html_e( 'Black Friday: 40% off AI Agent OS PRO — only sale of the year', 'zorem-ai-agent-connector' ); ?></strong>
				<p class="zui-pnotice__text aaos-offer__line"><?php self::details(); ?></p>
				<div class="zui-pnotice__actions"><?php self::button( 'black-friday-notice' ); ?></div>
			</div>
			<button type="button" class="zui-pnotice__close" data-aaos-offer-dismiss aria-label="<?php esc_attr_e( 'Dismiss this offer', 'zorem-ai-agent-connector' ); ?>">&times;</button>
		</div>
		<?php
	}

	/**
	 * The banner at the top of this plugin's screen: full width, not dismissible.
	 */
	public static function banner(): void {
		if ( ! self::is_running() || ! current_user_can( Capabilities::MANAGE ) ) {
			return;
		}
		?>
		<div class="zui-pnotice aaos-offer aaos-offer--banner" role="status">
			<div class="aaos-offer__tile" aria-hidden="true">
				<span class="aaos-offer__tile-eyebrow"><?php esc_html_e( 'Black Friday', 'zorem-ai-agent-connector' ); ?></span>
				<span class="aaos-offer__tile-big">40<small>%</small></span>
				<span class="aaos-offer__tile-sub"><?php esc_html_e( 'off AI Agent OS PRO', 'zorem-ai-agent-connector' ); ?></span>
			</div>
			<div class="zui-pnotice__body aaos-offer__body">
				<strong class="zui-pnotice__title"><?php esc_html_e( 'Black Friday: 40% off AI Agent OS PRO — only sale of the year', 'zorem-ai-agent-connector' ); ?></strong>
				<p class="zui-pnotice__text aaos-offer__line"><?php self::details(); ?></p>
			</div>
			<div class="aaos-offer__aside"><?php self::button( 'black-friday-banner' ); ?></div>
		</div>
		<?php
	}
}
