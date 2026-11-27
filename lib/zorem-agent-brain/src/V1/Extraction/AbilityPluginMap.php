<?php
/**
 * Which plugin each ability belongs to.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

use ZoremAgentBrain\V1\Extraction\AbilityOriginRecorder;
use ZoremAgentBrain\V1\Extraction\PluginLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Maps an ability name to the plugin that registered it.
 *
 * This is what makes derived knowledge access possible: an agent granted
 * `woocommerce/products-query` may read WooCommerce's public knowledge, and
 * that sentence only means something if we can say which plugin an ability
 * belongs to.
 *
 * The ability's namespace is not the answer. Anyone can register an ability
 * called `woocommerce/anything`, and treating the prefix as ownership would let
 * a plugin grant itself access to another plugin's knowledge by choosing a
 * name. The answer comes from `AbilityOriginRecorder`, which watched the
 * registration happen — the direct-observation source the attribution
 * evidence tiers treat as primary.
 *
 * An ability whose origin was never recorded maps to no plugin and therefore
 * derives no access. That is the fail-closed direction: an unmapped ability
 * grants nothing rather than granting something arbitrary.
 */
final class AbilityPluginMap implements AbilityPluginMapInterface {

	/**
	 * Registration-time origins.
	 *
	 * @var AbilityOriginRecorder
	 */
	private AbilityOriginRecorder $origins;

	/**
	 * File-to-plugin resolution.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Resolved map, ability name => plugin slug.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $map = null;

	/**
	 * Constructor.
	 *
	 * @param AbilityOriginRecorder $origins Registration-time origins.
	 * @param PluginLocator         $locator File-to-plugin resolution.
	 */
	public function __construct( AbilityOriginRecorder $origins, PluginLocator $locator ) {
		$this->origins = $origins;
		$this->locator = $locator;
	}

	/**
	 * The plugin that registered an ability, or null.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function plugin_for( string $ability_name ): ?string {
		return $this->all()[ $ability_name ] ?? null;
	}

	/**
	 * The plugins a set of abilities belongs to.
	 *
	 * @param array<int, string> $ability_names Ability names.
	 * @return array<int, string> Plugin slugs, unique.
	 */
	public function plugins_for( array $ability_names ): array {
		$slugs = array();

		foreach ( $ability_names as $name ) {
			$slug = $this->plugin_for( (string) $name );

			if ( null !== $slug && ! in_array( $slug, $slugs, true ) ) {
				$slugs[] = $slug;
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * The whole map.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		if ( null !== $this->map ) {
			return $this->map;
		}

		$this->map = array();

		foreach ( $this->origins->all() as $name => $file ) {
			$slug = $this->locator->plugin_for_file( (string) $file );

			if ( null !== $slug ) {
				$this->map[ (string) $name ] = $slug;
			}
		}

		return $this->map;
	}

	/**
	 * Clear the resolved map.
	 */
	public function flush(): void {
		$this->map = null;
	}
}
