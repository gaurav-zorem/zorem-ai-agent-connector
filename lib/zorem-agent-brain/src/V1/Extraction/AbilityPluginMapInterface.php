<?php
/**
 * Ability-to-plugin resolution contract.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Answers which plugin an ability belongs to.
 *
 * An interface rather than a filter, deliberately. Derived knowledge access
 * rests entirely on this mapping, so it is an authorisation input — and a
 * filter on an authorisation input is a hook that lets any code on the site
 * quietly widen what an agent can read. Substitution happens by construction,
 * where it is visible in Brain's service table, not at runtime by whoever hooks last.
 */
interface AbilityPluginMapInterface {

	/**
	 * The plugin that registered an ability, or null when unknown.
	 *
	 * @param string $ability_name Ability name.
	 */
	public function plugin_for( string $ability_name ): ?string;

	/**
	 * The plugins a set of abilities belongs to.
	 *
	 * @param array<int, string> $ability_names Ability names.
	 * @return array<int, string> Plugin slugs, unique.
	 */
	public function plugins_for( array $ability_names ): array;

	/**
	 * The whole map, ability name => plugin slug.
	 *
	 * @return array<string, string>
	 */
	public function all(): array;

	/**
	 * Drop any resolved state.
	 */
	public function flush(): void;
}
