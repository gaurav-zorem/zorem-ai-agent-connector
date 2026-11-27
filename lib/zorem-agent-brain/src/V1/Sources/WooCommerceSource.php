<?php
/**
 * What the store already knows about itself.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Sources;

use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce's own configuration, read into the Website Brain.
 *
 * ## Why this exists
 *
 * A merchant asked why their assistant could describe every hook in their
 * plugins but not their returns policy. The answer was that the Brain was fed
 * by reading plugin *code*, and a returns window is not in the code — it is in
 * WooCommerce's settings, where they typed it on the day they opened the shop.
 *
 * The shop already holds the answers to most of what a shopper asks. Where do
 * you ship, what does it cost, can I return this, are prices tax-inclusive,
 * can I check out without an account. Every one of those is a setting somebody
 * filled in, and none of it was reaching an agent.
 *
 * ## Two kinds of thing, deliberately handled differently
 *
 * **Policy pages** — Terms, Refund & Returns, Privacy. WooCommerce records
 * which page is which, so they are found rather than guessed at, and then
 * handed to `WpContentSource` rather than ingested here. That class already
 * knows how to flatten a page, bound it, checksum it, re-read it when it is
 * edited and drop it when it is trashed. Adding them to its selection means
 * they behave exactly like a page the merchant ticked by hand, and appear
 * ticked on the Store policies screen, which is the truth: they *are*
 * selected, just not by hand.
 *
 * **Settings** — shipping zones, the store address, the currency, guest
 * checkout. These are facts, not prose, and they have no page to point at, so
 * they are written here as one entry under `store_settings`.
 *
 * ## What is not here
 *
 * No prices, no stock levels, no product data. Those are read live by the
 * catalogue abilities and would be stale in the Brain within a day. This is
 * for the settings that change a few times a year.
 */
final class WooCommerceSource {

	/**
	 * The settings entry's key. One row, rewritten in place.
	 */
	public const SETTINGS_KEY = 'store:settings';

	/**
	 * Entry storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * The page ingester, reused rather than reimplemented.
	 *
	 * @var WpContentSource
	 */
	private WpContentSource $pages;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Entry storage.
	 * @param WpContentSource     $pages      Page ingester.
	 */
	public function __construct( KnowledgeRepository $repository, WpContentSource $pages ) {
		$this->repository = $repository;
		$this->pages      = $pages;
	}

	/**
	 * Re-read when the merchant changes something.
	 *
	 * WooCommerce fires `woocommerce_settings_saved` for its settings screens
	 * and `woocommerce_after_...zone...` for the shipping ones. Both are hooked
	 * so a change reaches the Brain on save rather than on the next tick — a
	 * merchant who corrects a shipping cost and immediately asks the assistant
	 * about it should not be told yesterday's answer.
	 */
	public function register(): void {
		add_action( 'woocommerce_settings_saved', array( $this, 'sync' ) );
		add_action( 'woocommerce_shipping_zone_method_added', array( $this, 'sync' ) );
		add_action( 'woocommerce_shipping_zone_method_deleted', array( $this, 'sync' ) );
		add_action( 'woocommerce_shipping_zone_method_status_toggled', array( $this, 'sync' ) );
		add_action( 'update_option_woocommerce_currency', array( $this, 'sync' ) );

		/*
		 * And on the extraction tick, which is the self-healing path. Settings
		 * changed by an import, by WP-CLI or by another plugin fire none of the
		 * hooks above, and a Brain that is only correct when somebody used the
		 * settings screen is a Brain nobody can trust.
		 */
		add_action( \ZoremAgentBrain\V1\Ingestion\IngestionScheduler::EVENT, array( $this, 'sync' ) );
	}

	/**
	 * Whether there is a store to read.
	 */
	public function is_available(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_page_id' );
	}

	// -----------------------------------------------------------------
	// Policy pages.
	// -----------------------------------------------------------------

	/**
	 * The pages WooCommerce itself knows the purpose of.
	 *
	 * Read from the options WooCommerce sets, not matched by title. A shop
	 * whose returns page is called "Changed your mind?" is found; a page called
	 * "Refund Policy" that the merchant never wired up is not, because
	 * WooCommerce would not point a customer at it either.
	 *
	 * @return array<string, int> Purpose => post id, for pages that exist.
	 */
	public function policy_pages(): array {
		$found = array();

		foreach ( $this->policy_page_status() as $purpose => $state ) {
			if ( '' === $state['reason'] ) {
				$found[ $purpose ] = $state['id'];
			}
		}

		return $found;
	}

	/**
	 * Every policy page WooCommerce can point at, and what became of it.
	 *
	 * Reported rather than filtered, because "no policies found" and "your
	 * refund policy is still a draft" are different situations and only one of
	 * them is the merchant's to fix. WooCommerce creates a *draft* "Refund and
	 * Returns Policy" page during setup and most shops never publish it — so
	 * the common case here is a page that exists, is not published, and would
	 * otherwise be silently absent from the Brain.
	 *
	 * @return array<string, array{id: int, reason: string}>
	 */
	public function policy_page_status(): array {
		if ( ! $this->is_available() ) {
			return array();
		}

		$candidates = array(
			'terms'          => (int) wc_get_page_id( 'terms' ),
			'refund_returns' => (int) get_option( 'woocommerce_refund_returns_page_id', 0 ),
			'privacy'        => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
		);

		$status = array();

		foreach ( $candidates as $purpose => $id ) {
			/*
			 * `wc_get_page_id()` returns -1 for "not set", and any of these can
			 * point at a page that has since been deleted.
			 */
			$state = get_post_status( $id );

			if ( $id < 1 || false === $state ) {
				$status[ $purpose ] = array(
					'id'     => 0,
					'reason' => __( 'No page is set for this in your settings.', 'zorem-ai-agent-connector' ),
				);

				continue;
			}

			if ( 'publish' !== $state ) {
				$status[ $purpose ] = array(
					'id'     => $id,
					'reason' => sprintf(
						/* translators: %s: post status, e.g. draft. */
						__( 'The page is %s, so shoppers cannot read it either.', 'zorem-ai-agent-connector' ),
						$state
					),
				);

				continue;
			}

			$status[ $purpose ] = array(
				'id'     => $id,
				'reason' => '',
			);
		}

		return $status;
	}

	/**
	 * A human label for what a page is for.
	 *
	 * @param string $purpose One of the policy_pages() keys.
	 */
	public static function purpose_label( string $purpose ): string {
		$labels = array(
			'terms'          => __( 'Terms & conditions', 'zorem-ai-agent-connector' ),
			'refund_returns' => __( 'Refunds & returns', 'zorem-ai-agent-connector' ),
			'privacy'        => __( 'Privacy policy', 'zorem-ai-agent-connector' ),
		);

		return $labels[ $purpose ] ?? $purpose;
	}

	/**
	 * The option that holds each policy page, keyed by purpose.
	 *
	 * @return array<string, string>
	 */
	public static function policy_options(): array {
		return array(
			'terms'          => 'woocommerce_terms_page_id',
			'refund_returns' => 'woocommerce_refund_returns_page_id',
			'privacy'        => 'wp_page_for_privacy_policy',
		);
	}

	/**
	 * Choose the page for one policy, in WooCommerce's own settings.
	 *
	 * Not a mapping of the Brain's own: which page is the refund policy is a
	 * fact about the shop that WooCommerce already keeps, so setting it here
	 * also puts the link where a customer meets it at checkout, and there is
	 * one record rather than two that can disagree.
	 *
	 * A page that is not published is refused rather than stored. Pointing a
	 * policy at a draft is how shops end up with empty policies, and accepting
	 * it would have this screen report the problem it was just used to create.
	 *
	 * @param string $purpose One of the policy_options() keys.
	 * @param int    $page_id Page id, or 0 to unset.
	 * @return array<string, mixed>|\WP_Error What sync() reports afterwards.
	 */
	public function set_policy_page( string $purpose, int $page_id ) {
		$options = self::policy_options();

		if ( ! $this->is_available() ) {
			return new \WP_Error( 'zorem_agent_brain_no_woocommerce', __( 'WooCommerce is not active, so there are no store policies to set.', 'zorem-ai-agent-connector' ), array( 'status' => 409 ) );
		}

		if ( ! isset( $options[ $purpose ] ) ) {
			return new \WP_Error( 'zorem_agent_brain_unknown_policy', __( 'That is not a policy this store keeps.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		if ( 0 !== $page_id && ( 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) || '' !== (string) get_post_field( 'post_password', $page_id ) ) ) {
			return new \WP_Error( 'zorem_agent_brain_page_not_published', __( 'That page is not published, so shoppers could not read it either.', 'zorem-ai-agent-connector' ), array( 'status' => 400 ) );
		}

		update_option( $options[ $purpose ], $page_id );

		return $this->sync();
	}

	// -----------------------------------------------------------------
	// Sync.
	// -----------------------------------------------------------------

	/**
	 * Read everything, and report what happened.
	 *
	 * @return array<string, mixed>
	 */
	public function sync(): array {
		if ( ! $this->is_available() ) {
			return array(
				'available' => false,
				'pages'     => array(),
				'settings'  => false,
			);
		}

		$pages = $this->policy_pages();

		/*
		 * Union, never replacement. The merchant's own selection is theirs; a
		 * store setting pointing at a page is a reason to add it, not a reason
		 * to drop the four pages they ticked themselves.
		 */
		if ( array() !== $pages ) {
			$this->pages->select(
				array_values( array_unique( array_merge( $this->pages->selected(), array_values( $pages ) ) ) )
			);
		}

		return array(
			'available' => true,
			'pages'     => $pages,
			'status'    => $this->policy_page_status(),
			'settings'  => $this->sync_settings(),
		);
	}

	/**
	 * Write the settings entry.
	 */
	private function sync_settings(): bool {
		$facts   = $this->facts();
		$content = $this->render( $facts );

		// md5: the column is char(32). Same constraint as every other producer.
		$checksum = md5( $content );

		$existing = $this->repository->find( self::SETTINGS_KEY );

		if ( null !== $existing && $existing->get_checksum() === $checksum ) {
			return false;
		}

		$result = $this->repository->upsert(
			array(
				'entry_key'    => self::SETTINGS_KEY,
				'source_type'  => KnowledgeEntry::SOURCE_STORE_SETTINGS,
				'source_ref'   => 'woocommerce',
				'plugin_slug'  => 'woocommerce',
				'section'      => KnowledgeEntry::SECTION_MANUAL,
				'title'        => __( 'How this store operates', 'zorem-ai-agent-connector' ),
				'summary'      => $this->summarise( $facts ),
				'content'      => $content,
				'facts'        => $facts,
				'checksum'     => $checksum,
				'trust_status' => KnowledgeEntry::TRUST_APPROVED,
				'meta'         => array(

					/*
					 * `configuration`, not `author_prose`: everything here was
					 * read from a settings row rather than typed into a page,
					 * and the admin labels it as store settings accordingly.
					 * It is still fenced when it reaches a model
					 * (`Provenance::is_verified()` is false for it) because the
					 * address and shipping-zone names are text the merchant
					 * typed, and nothing typed is exempt.
					 */
					'provenance' => array( 'content' => Provenance::CONFIGURATION ),
					'store'      => array(
						'read_at' => current_time( 'mysql', true ),
					),
				),
			)
		);

		return ! is_wp_error( $result );
	}

	// -----------------------------------------------------------------
	// Reading the store.
	// -----------------------------------------------------------------

	/**
	 * The settings worth knowing, as data.
	 *
	 * @return array<string, mixed>
	 */
	private function facts(): array {
		$base = wc_get_base_location();

		return array(
			'currency'           => get_woocommerce_currency(),
			'base_country'       => (string) ( $base['country'] ?? '' ),
			'base_state'         => (string) ( $base['state'] ?? '' ),
			'store_address'      => $this->address(),
			'sells_to'           => $this->selling_locations(),
			'ships_to'           => $this->shipping_locations(),
			'shipping_zones'     => $this->zones(),
			'guest_checkout'     => 'yes' === get_option( 'woocommerce_enable_guest_checkout' ),
			'coupons_enabled'    => 'yes' === get_option( 'woocommerce_enable_coupons' ),
			'prices_include_tax' => wc_prices_include_tax(),
			'taxes_enabled'      => wc_tax_enabled(),
			'stock_managed'      => 'yes' === get_option( 'woocommerce_manage_stock' ),
			'hides_out_of_stock' => 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ),
			'reviews_enabled'    => 'yes' === get_option( 'woocommerce_enable_reviews' ),
		);
	}

	/**
	 * The store's postal address, as one line.
	 */
	private function address(): string {
		$parts = array_filter(
			array(
				(string) get_option( 'woocommerce_store_address', '' ),
				(string) get_option( 'woocommerce_store_address_2', '' ),
				(string) get_option( 'woocommerce_store_city', '' ),
				(string) get_option( 'woocommerce_store_postcode', '' ),
			)
		);

		return implode( ', ', $parts );
	}

	/**
	 * Where the store will sell, in words.
	 */
	private function selling_locations(): string {
		$mode = (string) get_option( 'woocommerce_allowed_countries', 'all' );

		if ( 'all_except' === $mode ) {
			$except = (array) get_option( 'woocommerce_all_except_countries', array() );

			return sprintf(
				/* translators: %s: comma-separated country names. */
				__( 'Everywhere except %s', 'zorem-ai-agent-connector' ),
				$this->country_names( $except )
			);
		}

		if ( 'specific' === $mode ) {
			return $this->country_names( (array) get_option( 'woocommerce_specific_allowed_countries', array() ) );
		}

		return __( 'Every country', 'zorem-ai-agent-connector' );
	}

	/**
	 * Where the store will ship, in words.
	 */
	private function shipping_locations(): string {
		$mode = (string) get_option( 'woocommerce_ship_to_countries', '' );

		if ( 'disabled' === $mode ) {
			return __( 'Shipping is switched off — this store does not ship', 'zorem-ai-agent-connector' );
		}

		if ( 'specific' === $mode ) {
			return $this->country_names( (array) get_option( 'woocommerce_specific_ship_to_countries', array() ) );
		}

		if ( 'all' === $mode ) {
			return __( 'Every country', 'zorem-ai-agent-connector' );
		}

		/*
		 * The default is "ship to the countries you sell to", stored as an
		 * empty string. Resolving it rather than repeating it: "ships to
		 * wherever the store sells" is a rule, and a shopper asking whether you
		 * deliver to Ireland needs the answer, not the rule that produces it.
		 */
		return $this->selling_locations();
	}

	/**
	 * Country codes as names, because an agent quoting "GB" helps nobody.
	 *
	 * @param array<int, string> $codes Country codes.
	 */
	private function country_names( array $codes ): string {
		if ( array() === $codes ) {
			return __( 'nowhere', 'zorem-ai-agent-connector' );
		}

		$all   = WC()->countries ? WC()->countries->get_countries() : array();
		$names = array();

		foreach ( $codes as $code ) {
			$names[] = (string) ( $all[ $code ] ?? $code );
		}

		return implode( ', ', $names );
	}

	/**
	 * Shipping zones with the methods a shopper would actually be offered.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function zones(): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return array();
		}

		$zones = \WC_Shipping_Zones::get_zones();

		/*
		 * Zone 0 is "Locations not covered by your other zones", which
		 * `get_zones()` leaves out and which is the one that decides what
		 * happens to an order from somewhere unexpected — the exact case a
		 * shopper is asking about when they ask whether you ship to them.
		 */
		$rest = \WC_Shipping_Zones::get_zone( 0 );

		if ( $rest ) {
			$zones[] = array(
				'zone_name'               => $rest->get_zone_name(),
				'formatted_zone_location' => __( 'Everywhere else', 'zorem-ai-agent-connector' ),
				'shipping_methods'        => $rest->get_shipping_methods( true ),
			);
		}

		$out = array();

		foreach ( $zones as $zone ) {
			$methods = array();

			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
				if ( ! is_object( $method ) || 'yes' !== $method->enabled ) {
					continue;
				}

				$methods[] = array(
					'title' => $method->get_title(),
					'cost'  => $this->method_cost( $method ),
				);
			}

			$out[] = array(
				'name'    => (string) ( $zone['zone_name'] ?? '' ),
				'regions' => (string) ( $zone['formatted_zone_location'] ?? '' ),
				'methods' => $methods,
			);
		}

		return $out;
	}

	/**
	 * What a shipping method costs, when it says so plainly.
	 *
	 * Free shipping and flat rate can be stated. Anything computed at checkout
	 * — table rates, carrier-calculated rates — is reported as "calculated at
	 * checkout" rather than guessed at, because an agent quoting a shipping
	 * price the checkout then contradicts is worse than one that says it does
	 * not know.
	 *
	 * @param object $method A shipping method instance.
	 */
	private function method_cost( object $method ): string {
		$id = method_exists( $method, 'get_method_title' ) ? $method->id : '';

		if ( 'free_shipping' === $id ) {
			return __( 'Free', 'zorem-ai-agent-connector' );
		}

		if ( 'flat_rate' === $id ) {
			$cost = (string) ( $method->get_option( 'cost' ) ?? '' );

			// A cost with [qty] or a formula in it is not a number to quote.
			if ( '' !== $cost && is_numeric( $cost ) ) {
				return html_entity_decode( wp_strip_all_tags( wc_price( (float) $cost ) ) );
			}
		}

		if ( 'local_pickup' === $id ) {
			return __( 'Collection in person', 'zorem-ai-agent-connector' );
		}

		return __( 'Calculated at checkout', 'zorem-ai-agent-connector' );
	}

	// -----------------------------------------------------------------
	// Rendering.
	// -----------------------------------------------------------------

	/**
	 * The facts as prose an agent can quote.
	 *
	 * Written as sentences rather than a settings dump: an agent asked "do you
	 * ship to Ireland" reproduces what it reads, and "ship_to_countries:
	 * specific" is not an answer anybody wants read back to them.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function render( array $facts ): string {
		$lines = array();

		$lines[] = '## Where this store sells and ships';
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: country or countries. */
			__( 'Sells to: %s.', 'zorem-ai-agent-connector' ),
			$facts['sells_to']
		);
		$lines[] = sprintf(
			/* translators: %s: country or countries. */
			__( 'Ships to: %s.', 'zorem-ai-agent-connector' ),
			$facts['ships_to']
		);

		if ( '' !== $facts['store_address'] ) {
			$lines[] = sprintf(
				/* translators: %s: postal address. */
				__( 'The store is based at %s.', 'zorem-ai-agent-connector' ),
				$facts['store_address']
			);
		}

		$lines[] = sprintf(
			/* translators: %s: currency code. */
			__( 'Prices are in %s.', 'zorem-ai-agent-connector' ),
			$facts['currency']
		);

		if ( array() !== $facts['shipping_zones'] ) {
			$lines[] = '';
			$lines[] = '## Shipping options';
			$lines[] = '';

			foreach ( $facts['shipping_zones'] as $zone ) {
				if ( array() === $zone['methods'] ) {
					$lines[] = sprintf(
						/* translators: 1: zone name, 2: regions in the zone. */
						__( '- %1$s (%2$s): no shipping method is set up, so orders from there cannot be shipped.', 'zorem-ai-agent-connector' ),
						$zone['name'],
						$zone['regions']
					);

					continue;
				}

				$offers = array();

				foreach ( $zone['methods'] as $method ) {
					$offers[] = $method['title'] . ' — ' . $method['cost'];
				}

				$lines[] = sprintf(
					/* translators: 1: zone name, 2: regions, 3: list of methods and costs. */
					__( '- %1$s (%2$s): %3$s.', 'zorem-ai-agent-connector' ),
					$zone['name'],
					$zone['regions'],
					implode( '; ', $offers )
				);
			}
		}

		$lines[] = '';
		$lines[] = '## Checkout';
		$lines[] = '';
		$lines[] = $facts['guest_checkout']
			? __( 'Shoppers may check out without creating an account.', 'zorem-ai-agent-connector' )
			: __( 'An account is required to check out.', 'zorem-ai-agent-connector' );

		$lines[] = $facts['coupons_enabled']
			? __( 'Coupon codes can be used at checkout.', 'zorem-ai-agent-connector' )
			: __( 'Coupon codes are switched off.', 'zorem-ai-agent-connector' );

		if ( $facts['taxes_enabled'] ) {
			$lines[] = $facts['prices_include_tax']
				? __( 'Displayed prices include tax.', 'zorem-ai-agent-connector' )
				: __( 'Tax is added at checkout, so displayed prices exclude it.', 'zorem-ai-agent-connector' );
		} else {
			$lines[] = __( 'This store does not charge tax.', 'zorem-ai-agent-connector' );
		}

		$lines[] = '';
		$lines[] = '## Stock';
		$lines[] = '';
		$lines[] = $facts['stock_managed']
			? __( 'Stock levels are tracked.', 'zorem-ai-agent-connector' )
			: __( 'Stock levels are not tracked, so nothing is ever out of stock.', 'zorem-ai-agent-connector' );

		if ( $facts['hides_out_of_stock'] ) {
			$lines[] = __( 'Out-of-stock products are hidden from the catalogue.', 'zorem-ai-agent-connector' );
		}

		return implode( "\n", $lines );
	}

	/**
	 * One sentence, for a list that has no room for more.
	 *
	 * @param array<string, mixed> $facts Facts.
	 */
	private function summarise( array $facts ): string {
		$zones = count( (array) $facts['shipping_zones'] );

		return sprintf(
			/* translators: 1: shipping destinations, 2: currency, 3: shipping zone count phrase. */
			__( 'Ships to %1$s. Prices in %2$s, %3$s. Read from your WooCommerce settings.', 'zorem-ai-agent-connector' ),
			$facts['ships_to'],
			$facts['currency'],
			sprintf(
				/* translators: %d: number of shipping zones. */
				_n( '%d shipping zone', '%d shipping zones', $zones, 'zorem-ai-agent-connector' ),
				$zones
			)
		);
	}
}
