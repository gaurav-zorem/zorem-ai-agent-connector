<?php
/**
 * Ability inventory.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use AiAgentOs\Abilities\CoreAbilities;
use AiAgentOs\Governance\CapMaps\CapMapLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Enumerates every ability registered on the site.
 *
 * This is the discovery mechanism the product's "reads all your plugins"
 * promise rests on. Any plugin that registers abilities — WooCommerce,
 * Elementor, our own — appears here automatically, with its schema, category
 * and description already structured. No code parsing required.
 */
final class AbilityInventory {

	/**
	 * Risk classifier.
	 *
	 * @var RiskClassifier
	 */
	private RiskClassifier $risk;

	/**
	 * Ability to capability mapping.
	 *
	 * @var CapMapLoader
	 */
	private CapMapLoader $cap_maps;

	/**
	 * Per-request cache of the descriptor list.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $descriptors = null;

	/**
	 * Constructor.
	 *
	 * @param RiskClassifier $risk     Risk classifier.
	 * @param CapMapLoader   $cap_maps Ability to capability mapping.
	 */
	public function __construct( RiskClassifier $risk, CapMapLoader $cap_maps ) {
		$this->risk     = $risk;
		$this->cap_maps = $cap_maps;
	}

	/**
	 * Every registered ability object, keyed by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	public function all(): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities();
		$keyed     = array();

		foreach ( $abilities as $ability ) {
			if ( $ability instanceof \WP_Ability ) {
				$keyed[ $ability->get_name() ] = $ability;
			}
		}

		return $keyed;
	}

	/**
	 * Abilities that may be exposed to the agent at all.
	 *
	 * Some abilities should never reach the agent regardless of grants — the MCP
	 * adapter's own execute-ability meta-tool being the obvious example, since
	 * it would let a caller run anything by name and route straight around a
	 * per-tool allow-list.
	 *
	 * @return array<string, \WP_Ability>
	 */
	public function grantable(): array {
		$abilities = $this->all();

		foreach ( $abilities as $name => $ability ) {
			if ( $this->is_hidden( (string) $name ) ) {
				unset( $abilities[ $name ] );
				continue;
			}

			/*
			 * Resource projections (Brain entries) are derived access, decided
			 * by the Brain for the agent's reader. They are not matrix rows
			 * (nothing to grant) and not tools (they are listed as MCP
			 * resources instead), so they do not belong in this set.
			 */
			if ( $this->is_resource_projection( $ability ) ) {
				unset( $abilities[ $name ] );
			}
		}

		return $abilities;
	}

	/**
	 * Whether an ability exists only to carry a resource.
	 *
	 * The test is what the ability *is* — `mcp.type` — rather
	 * than which subsystem produced it, so a third kind of projection added
	 * later is excluded from the matrix without anyone having to remember to
	 * extend this. Resource projections are not grantable: there is nothing to
	 * grant, and their visibility is decided by the subsystem that owns them.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	public function is_resource_projection( \WP_Ability $ability ): bool {
		$mcp = $ability->get_meta_item( 'mcp', array() );

		return is_array( $mcp ) && 'resource' === ( $mcp['type'] ?? '' );
	}

	/**
	 * Whether an ability is hidden from the agent on this site.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function is_hidden( string $ability_name ): bool {
		/**
		 * Filters the abilities that may never be granted to the agent.
		 *
		 * @param array<int, string> $hidden Ability names.
		 */
		$hidden = (array) apply_filters(
			'ai_agent_os_hidden_abilities',
			array(
				'mcp-adapter/execute-ability',
				'mcp-adapter/discover-abilities',
				'mcp-adapter/get-ability-info',
			)
		);

		if ( in_array( $ability_name, $hidden, true ) ) {
			return true;
		}

		/**
		 * Filters whether one ability is kept off the permission matrix and the
		 * MCP server entirely.
		 *
		 * Every ability registered on the site is governed here, whichever
		 * plugin registered it: it appears on the matrix, granted to nothing
		 * until an administrator grants it, rated by `RiskClassifier`, and run
		 * with only the capabilities `CapabilityBridge` will lend it. Return
		 * true to withhold one from that set on a particular site.
		 *
		 * @param bool   $hidden       Whether the ability stays hidden. Default false.
		 * @param string $ability_name Ability name.
		 */
		return (bool) apply_filters( 'ai_agent_os_hide_ability', false, $ability_name );
	}

	/**
	 * One ability by name.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function get( string $ability_name ): ?\WP_Ability {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		/*
		 * Check first. wp_get_ability() on an unregistered name routes through
		 * WP_Abilities_Registry::get_registered(), which treats it as developer
		 * error and calls _doing_it_wrong(). Ability names reaching this method
		 * come off the wire — the agent naming a tool that does not exist is an
		 * ordinary, expected event, and the adversarial case besides. Letting
		 * it raise a core notice would fill the debug log on demand from an
		 * unauthenticated-shaped input.
		 */
		if ( ! $this->has( $ability_name ) ) {
			return null;
		}

		$ability = wp_get_ability( $ability_name );

		return $ability instanceof \WP_Ability ? $ability : null;
	}

	/**
	 * Whether an ability exists.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function has( string $ability_name ): bool {
		return function_exists( 'wp_has_ability' ) && wp_has_ability( $ability_name );
	}

	/**
	 * Describe every grantable ability for the admin UI.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function descriptors(): array {
		if ( null !== $this->descriptors ) {
			return $this->descriptors;
		}

		$descriptors = array();

		foreach ( $this->grantable() as $name => $ability ) {
			$known = $this->cap_maps->resolve( $ability );
			$caps  = $known ?? array();

			$descriptors[ $name ] = array(
				'name'          => $name,
				'label'         => AbilityLabels::ability( $ability->get_name(), (string) $ability->get_label() ),
				'description'   => $ability->get_description(),
				'category'      => $ability->get_category(),
				'risk'          => $this->risk->classify( $ability ),
				'risk_inferred' => $this->risk->is_inferred( $ability ),
				'namespace'     => $this->namespace_of( (string) $name ),
				'required_caps' => $caps,

				/*
				 * Surfaced in the UI: nothing describes this ability's
				 * capabilities, so CapabilityBridge lends them on demand. An
				 * ability that declares it needs none is not unknown.
				 */
				'caps_unknown'  => null === $known,
				'has_input'     => array() !== $ability->get_input_schema(),
			);
		}

		ksort( $descriptors );

		$this->descriptors = $descriptors;

		return $descriptors;
	}

	/**
	 * Descriptors grouped by ability category, for the permission matrix.
	 *
	 * @return array<string, array{label: string, subtitle: string, abilities: array<int, array<string, mixed>>}>
	 */
	public function grouped_descriptors(): array {
		$groups = array();

		foreach ( $this->descriptors() as $descriptor ) {
			$category = (string) $descriptor['category'];

			if ( ! isset( $groups[ $category ] ) ) {
				$groups[ $category ] = array(
					'label'     => $this->category_label( $category ),
					'subtitle'  => $this->category_subtitle( $category ),
					'abilities' => array(),
				);
			}

			$groups[ $category ]['abilities'][] = $descriptor;
		}

		uksort( $groups, fn( string $a, string $b ): int => $this->compare_categories( $a, $b, $groups ) );

		// Inside a section, by the name people read, not the ability's technical name.
		foreach ( $groups as &$group ) {
			usort( $group['abilities'], static fn( array $x, array $y ): int => strnatcasecmp( (string) $x['label'], (string) $y['label'] ) );
		}
		unset( $group );

		return $groups;
	}

	/**
	 * The fixed place of known sections on the permission matrix, first to last.
	 *
	 * WordPress basics first, then this plugin's tools in everyday-use order,
	 * then WooCommerce. A section not listed here (another plugin's) comes
	 * after all of these, alphabetically by its label.
	 */
	private const CATEGORY_ORDER = array(
		'site',
		'user',
		'ai-agent-os-content',
		'ai-agent-os-pages',
		'ai-agent-os-media',
		'ai-agent-os-comments',
		'ai-agent-os-cpt',
		'ai-agent-os-menus',
		'ai-agent-os-site-building',
		'ai-agent-os-users',
		'ai-agent-os-site',
		'ai-agent-os',
		'woocommerce',
		'woocommerce-rest',
	);

	/**
	 * Order two sections: known ones by CATEGORY_ORDER, the rest after them by label.
	 *
	 * @param string                              $a      Category slug.
	 * @param string                              $b      Category slug.
	 * @param array<string, array<string, mixed>> $groups The groups, for their labels.
	 */
	private function compare_categories( string $a, string $b, array $groups ): int {
		$rank_a = array_search( $a, self::CATEGORY_ORDER, true );
		$rank_b = array_search( $b, self::CATEGORY_ORDER, true );
		$last   = count( self::CATEGORY_ORDER );
		$rank_a = false === $rank_a ? $last : (int) $rank_a;
		$rank_b = false === $rank_b ? $last : (int) $rank_b;

		if ( $rank_a !== $rank_b ) {
			return $rank_a <=> $rank_b;
		}

		$by_label = strnatcasecmp( (string) $groups[ $a ]['label'], (string) $groups[ $b ]['label'] );

		return 0 !== $by_label ? $by_label : strcmp( $a, $b );
	}

	/**
	 * A category's human-readable label.
	 *
	 * @param string $slug Category slug.
	 */
	private function category_label( string $slug ): string {
		if ( function_exists( 'wp_get_ability_category' ) ) {
			$category = wp_get_ability_category( $slug );

			if ( $category && method_exists( $category, 'get_label' ) ) {
				return AbilityLabels::category( $slug, (string) $category->get_label() );
			}
		}

		return AbilityLabels::category( $slug, ucwords( str_replace( array( '-', '_' ), ' ', $slug ) ) );
	}

	/**
	 * Where a category's abilities come from, in three or four words.
	 *
	 * Sits beside the section name in the permission matrix, so a merchant
	 * reading "SITE" knows whether they are about to grant something belonging
	 * to WordPress itself or to a plugin they installed last week.
	 *
	 * A short list rather than the registered category description, which is a
	 * full sentence written to be read on its own ("Abilities that retrieve or
	 * modify site information and settings") and says nothing about
	 * provenance. A category we do not recognise gets no subtitle: inventing
	 * one would be guessing at exactly the fact the line exists to state.
	 *
	 * @param string $slug Category slug.
	 */
	private function category_subtitle( string $slug ): string {
		$known = array(
			'site'             => __( 'WordPress core', 'zorem-ai-agent-connector' ),
			'user'             => __( 'Session & auth', 'zorem-ai-agent-connector' ),
			'woocommerce'      => __( 'WooCommerce core plugin', 'zorem-ai-agent-connector' ),
			'woocommerce-rest' => __( 'WooCommerce REST API', 'zorem-ai-agent-connector' ),
			'ai-agent-os'      => __( 'This plugin', 'zorem-ai-agent-connector' ),
		);

		if ( array_key_exists( $slug, CoreAbilities::categories() ) ) {
			return __( 'This plugin', 'zorem-ai-agent-connector' );
		}

		return (string) ( $known[ $slug ] ?? '' );
	}

	/**
	 * The namespace portion of an ability name.
	 *
	 * @param string $name Ability name.
	 */
	private function namespace_of( string $name ): string {
		$parts = explode( '/', $name, 2 );

		return $parts[0] ?? '';
	}

	/**
	 * Resolve wildcard patterns like "woocommerce/*" or "*refund*" against the
	 * live registry.
	 *
	 * Profiles are written before we know which plugins a site has, so they
	 * describe intent with patterns and we resolve it here.
	 *
	 * @param string $pattern Pattern with * wildcards.
	 * @return array<int, string> Matching ability names.
	 */
	public function match_pattern( string $pattern ): array {
		$regex   = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
		$matched = array();

		foreach ( array_keys( $this->grantable() ) as $name ) {
			if ( preg_match( $regex, (string) $name ) ) {
				$matched[] = (string) $name;
			}
		}

		return $matched;
	}

	/**
	 * Clear caches. Used by tests and after abilities are registered late.
	 */
	public function flush(): void {
		$this->descriptors = null;
	}
}
