<?php
/**
 * Registration of the Brain's retrieval abilities.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `agent-brain/*` once per request, whichever host loaded the kit.
 *
 * The abilities belong to the kit, not to either plugin, so a site running both
 * plugins has one set — not two competing copies under two prefixes. Each one
 * reaches storage only through KnowledgeGate — fetched from Brain::gate() when
 * it runs, not captured at registration — and each permission callback
 * requires a reader resolvable from the acting agent: with no host resolving
 * that agent's namespace, nothing is readable.
 */
final class Registrar {

	public const CATEGORY = 'agent-brain';

	/**
	 * The ability names this kit registers.
	 *
	 * @return array<int, string>
	 */
	public static function names(): array {
		return array( ListTopics::NAME, SearchKnowledge::NAME, GetEntry::NAME );
	}

	/**
	 * Register the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Website Brain', 'zorem-ai-agent-connector' ),
				'description' => __( 'Reading what the store knows, within what the reader may read.', 'zorem-ai-agent-connector' ),
			)
		);
	}

	/**
	 * Register the three retrieval abilities.
	 */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$definitions = array(
			ListTopics::NAME      => ListTopics::definition(),
			SearchKnowledge::NAME => SearchKnowledge::definition(),
			GetEntry::NAME        => GetEntry::definition(),
		);

		foreach ( $definitions as $name => $definition ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}

			wp_register_ability( $name, $definition );
		}
	}
}
