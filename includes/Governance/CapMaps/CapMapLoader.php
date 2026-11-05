<?php
/**
 * Ability to capability map loading.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance\CapMaps;

use AiAgentOs\Abilities\CoreAbilities;
use AiAgentOs\Governance\CapabilityBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which WordPress capabilities an ability's own permission callback
 * will demand.
 *
 * Needed because agents are not users. A shadow user holds 'read' and nothing
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
 *   2. our curated maps: WooCommerce's, selected by its installed version,
 *      and one per supported plugin that is actually active;
 *   3. the ai_agent_os_ability_required_caps filter, for everyone else.
 *
 * An ability that resolves to nothing runs in CapabilityBridge's automatic
 * mode, and the admin labels it "Capabilities lent automatically".
 */
final class CapMapLoader {

	/**
	 * Loaded curated map.
	 *
	 * @var array<string, array<int, string>>|null
	 */
	private ?array $map = null;

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
			$map     = $this->curated_map();
			$known   = array_key_exists( $name, $map );
			$caps    = $map[ $name ] ?? array();
			$curated = array_map( 'strval', $caps );
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
					return in_array( $cap, $curated, true ) || ! CapabilityBridge::is_never_lent( $cap );
				}
			)
		);

		// A filter that supplied capabilities is as good as a declaration.
		return $known || array() !== $caps ? $caps : null;
	}

	/**
	 * The curated map for the installed WooCommerce version.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function curated_map(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$this->map = array();

		/**
		 * Filters the curated capability map.
		 *
		 * This plugin ships no curated maps: it governs the tools it registers
		 * itself, and those declare their own capabilities. A plugin that wants
		 * its abilities mapped can supply one here, or declare
		 * `meta.zorem.required_caps` on the ability. An ability no map covers is
		 * lent the capabilities it asks for, for that call only, and never the
		 * ones that would let it change users, plugins or site code.
		 *
		 * @param array<string, array<int, string>> $map Ability name => capabilities.
		 */
		$filtered = apply_filters( 'ai_agent_os_curated_cap_map', array() );

		if ( is_array( $filtered ) ) {
			$this->map = $filtered;
		}

		return $this->map;
	}
}
