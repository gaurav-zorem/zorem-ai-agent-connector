<?php
/**
 * Ability to capability map loading.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance\CapMaps;

use AiAgentOs\Abilities\CoreAbilities;
use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Governance\CapabilityBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which WordPress capabilities an ability's own permission callback
 * will demand.
 *
 * Needed because the agent is not a user. A shadow user holds 'read' and nothing
 * else, but WooCommerce's ability callbacks ask current_user_can() questions
 * that a 'read'-only user always fails. CapabilityBridge answers those
 * questions for the duration of one call, and this class tells it what to
 * answer.
 *
 * Resolution order, most authoritative first:
 *   0. for an ability this plugin registered itself (the very object, not
 *      just the name), the list in its class, which may name capabilities
 *      that are never lent automatically, as a curated map may;
 *   1. the ability's own meta.zorem.required_caps, if its author declared it;
 *   2. the curated map: WooCommerce's, shipped here and selected by its
 *      installed version (which may name capabilities that are never lent
 *      automatically), plus entries from the ai_agent_os_curated_cap_map
 *      filter (which may not);
 *   3. the ai_agent_os_ability_required_caps filter, for everyone else.
 *
 * An ability that resolves to nothing runs in CapabilityBridge's automatic
 * mode, and the admin labels it "Capabilities lent automatically".
 */
final class CapMapLoader {

	/**
	 * WooCommerce maps this plugin ships, newest last, keyed by the minimum
	 * WooCommerce version. The 11.0 map is verified back to 10.9 (see its
	 * header); a WooCommerce older than every key falls back to the oldest.
	 *
	 * @var array<string, string>
	 */
	private const WOOCOMMERCE_MAPS = array(
		'11.0' => 'woocommerce-11.0.php',
		'11.3' => 'woocommerce-11.3.php',
	);

	/**
	 * Loaded curated map.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $map = null;

	/**
	 * Ability names whose entry came from a map this plugin ships, name => true.
	 *
	 * Only these may name a capability that is never lent automatically:
	 * each was written by reading that ability's permission callback. An
	 * entry added through the `ai_agent_os_curated_cap_map` filter is treated
	 * like a declaration.
	 *
	 * @var array<string, bool>
	 */
	private array $shipped = array();

	/**
	 * The WooCommerce map file in use, for Diagnostics. Empty when none is.
	 *
	 * @var string
	 */
	private string $loaded_map = '';

	/**
	 * This plugin's own abilities, which name their capabilities themselves.
	 *
	 * @var CoreAbilities|null
	 */
	private ?CoreAbilities $own;

	/**
	 * Constructor.
	 *
	 * @param CoreAbilities|null $own This plugin's own abilities; null leaves them unmapped.
	 */
	public function __construct( ?CoreAbilities $own = null ) {
		$this->own = $own;
	}

	/**
	 * Capabilities required to run an ability.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array<int, string>
	 */
	public function required_caps( \WP_Ability $ability ): array {
		return $this->resolve( $ability ) ?? array();
	}

	/**
	 * Capabilities required to run an ability, or null when nothing knows.
	 *
	 * The difference matters. An ability that declares an empty list — Agent
	 * OS's own do, because their callbacks check no capability — needs
	 * nothing lent. An ability nothing describes runs in CapabilityBridge's
	 * automatic mode. Collapsing the two would send every self-declared
	 * ability through on-demand lending it never asked for.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array<int, string>|null
	 */
	public function resolve( \WP_Ability $ability ): ?array {
		$name    = $ability->get_name();
		$meta    = $ability->get_meta();
		$known   = true;
		$curated = array();

		$own = null !== $this->own ? $this->own->caps_for( $ability ) : null;

		if ( null !== $own ) {
			// Our own ability, the very object we registered: its list was written by reading its code.
			$caps    = $own;
			$curated = $own;
		} elseif ( isset( $meta['zorem']['required_caps'] ) && is_array( $meta['zorem']['required_caps'] ) ) {
			$caps = $meta['zorem']['required_caps'];
		} else {
			/*
			 * An entry from a map this plugin ships (WooCommerce's, loaded only
			 * while WooCommerce runs) was written by reading the ability's code,
			 * so it may name a capability that is never lent automatically.
			 * One added through the `ai_agent_os_curated_cap_map` filter, which
			 * any plugin can hook, is treated like a declaration: it may not.
			 */
			$map   = $this->curated_map();
			$known = array_key_exists( $name, $map );
			$caps  = $map[ $name ] ?? array();

			if ( isset( $this->shipped[ $name ] ) ) {
				$curated = array_map( 'strval', $caps );
			}
		}

		/**
		 * Filters the capabilities an ability requires.
		 *
		 * Third-party ability authors should either declare
		 * meta.zorem.required_caps on registration or hook this filter.
		 * Without one of the two, the ability runs in CapabilityBridge's
		 * automatic mode: capabilities are lent as the ability asks for them,
		 * except the ones that are never lent automatically.
		 *
		 * @param array<int, string> $caps    Capability names.
		 * @param \WP_Ability        $ability The ability.
		 */
		$caps = (array) apply_filters( 'ai_agent_os_ability_required_caps', $caps, $ability );
		$caps = array_values( array_unique( array_filter( array_map( 'strval', $caps ) ) ) );

		/*
		 * Only our own curated maps may name a capability that is never lent:
		 * that is a decision made by reading the ability's code. A plugin's own
		 * meta or a filter could otherwise ask for user or plugin management
		 * just by declaring it, which the automatic mode refuses.
		 */
		$caps = array_values(
			array_filter(
				$caps,
				static function ( string $cap ) use ( $curated ): bool {
					return in_array( $cap, $curated, true ) || ! CapabilityBridge::is_never_lent_for( $cap, AgentContext::get() );
				}
			)
		);

		// A filter that supplied capabilities is as good as a declaration.
		return $known || array() !== $caps ? $caps : null;
	}

	/**
	 * The curated map: WooCommerce's for its installed version, plus any filter entries.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function curated_map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$woocommerce   = $this->woocommerce_map();
		$this->shipped = array_fill_keys( array_keys( $woocommerce ), true );

		/**
		 * Filters the curated capability map.
		 *
		 * This plugin ships one curated map, WooCommerce's, used while
		 * WooCommerce is active. A plugin that wants its abilities mapped can
		 * supply entries here, or declare `meta.zorem.required_caps` on the
		 * ability. A filter entry may not name a capability that is never lent
		 * automatically, and cannot replace a shipped WooCommerce entry. An
		 * ability no map covers is lent the capabilities it asks for, for that
		 * call only, and never the ones that would let it change users, plugins,
		 * site settings, other people's content or site code.
		 *
		 * @param array<string, array<int, string>> $map Ability name => capabilities.
		 */
		$filtered = apply_filters( 'ai_agent_os_curated_cap_map', array() );

		// The shipped map wins on a clash: it is keyed to WooCommerce's own names.
		$this->map = $woocommerce + ( is_array( $filtered ) ? $filtered : array() );

		return $this->map;
	}

	/**
	 * The shipped map for the installed WooCommerce version, or none.
	 *
	 * Records which file it used, for Diagnostics.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function woocommerce_map(): array {
		$this->loaded_map = '';

		if ( ! defined( 'WC_VERSION' ) ) {
			return array();
		}

		$file = $this->select_map_file( (string) WC_VERSION );
		$path = __DIR__ . '/' . $file;

		if ( '' === $file || ! is_readable( $path ) ) {
			return array();
		}

		$loaded = require $path;

		if ( ! is_array( $loaded ) ) {
			return array();
		}

		$this->loaded_map = $file;

		return $loaded;
	}

	/**
	 * Pick the newest shipped map that is not newer than the installed WooCommerce.
	 *
	 * A WooCommerce newer than every map falls back to the newest: the
	 * alternative is no capabilities at all, which would break every granted
	 * WooCommerce tool the day WooCommerce releases. If the mapping has drifted,
	 * the tool fails its own permission check, which is the safe direction.
	 * One older than every map falls back to the oldest.
	 *
	 * @param string $wc_version Installed WooCommerce version.
	 */
	private function select_map_file( string $wc_version ): string {
		$selected = '';

		foreach ( self::WOOCOMMERCE_MAPS as $min_version => $file ) {
			if ( version_compare( $wc_version, (string) $min_version, '>=' ) ) {
				$selected = $file;
			}
		}

		if ( '' === $selected ) {
			// Copied first: reset() takes its array by reference, and a constant cannot be passed so.
			$maps     = self::WOOCOMMERCE_MAPS;
			$selected = (string) reset( $maps );
		}

		return $selected;
	}

	/**
	 * Which WooCommerce map is in use, for Diagnostics. Empty without WooCommerce.
	 */
	public function get_loaded_map(): string {
		$this->curated_map();

		return $this->loaded_map;
	}

	/**
	 * Whether the installed WooCommerce is newer than every map this plugin ships.
	 *
	 * Compared on major.minor: maps are keyed to a minor series, so 11.3.2 is
	 * covered by the 11.3 map and 11.4 is not.
	 */
	public function is_map_stale(): bool {
		if ( ! defined( 'WC_VERSION' ) ) {
			return false;
		}

		$newest = (string) array_key_last( self::WOOCOMMERCE_MAPS );
		$parts  = explode( '.', (string) WC_VERSION );
		$series = ( $parts[0] ?? '0' ) . '.' . ( $parts[1] ?? '0' );

		return version_compare( $series, $newest, '>' );
	}
}
