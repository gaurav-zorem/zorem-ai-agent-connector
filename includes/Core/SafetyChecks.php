<?php
/**
 * Conditions that weaken governance, for Diagnostics.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

use AiAgentOs\Governance\GovernedAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * What an administrator should know is not as it should be.
 *
 * Each is something this plugin already handles safely — refusing the
 * ability, or running regardless — but which only a person can fix: another
 * plugin that reads the abilities registry too early, an ability class that
 * bypasses the gate, a bridge copy that is not this plugin's own.
 */
final class SafetyChecks {

	/**
	 * Which abilities the gate wrapped.
	 *
	 * @var GovernedAbilities
	 */
	private GovernedAbilities $governed;

	/**
	 * Constructor.
	 *
	 * @param GovernedAbilities $governed Which abilities the gate wrapped.
	 */
	public function __construct( GovernedAbilities $governed ) {
		$this->governed = $governed;
	}

	/**
	 * Every warning, as a sentence. Empty when all is well.
	 *
	 * @return array<int, string>
	 */
	public function warnings(): array {
		$warnings = array();

		if ( $this->governed->late() ) {
			$warnings[] = __( 'Another plugin read the WordPress abilities list before this plugin could oversee it. Abilities registered before that point are refused to the agent. Deactivate plugins one at a time to find which one, and ask its author to wait for the abilities API to start.', 'zorem-ai-agent-connector' );
		}

		foreach ( $this->governed->ungoverned() as $name => $reason ) {
			$warnings[] = 'ability_class' === $reason
				/* translators: %s: ability name. */
				? sprintf( __( 'The ability %s uses its own class, which runs without this plugin overseeing it, so the agent may not use it.', 'zorem-ai-agent-connector' ), $name )
				/* translators: %s: ability name. */
				: sprintf( __( 'The ability %s was registered without a permission or execute callback, so no agent may use it.', 'zorem-ai-agent-connector' ), $name );
		}

		// This plugin's copy, or another of our plugins': both are ours.
		$bridge = defined( 'ZOREM_AGENT_BRIDGE_DIR' ) ? trailingslashit( wp_normalize_path( (string) ZOREM_AGENT_BRIDGE_DIR ) ) : '';
		$ours   = array(
			trailingslashit( wp_normalize_path( AI_AGENT_OS_PATH . 'lib/zorem-agent-bridge' ) ),
			trailingslashit( wp_normalize_path( WP_PLUGIN_DIR . '/ai-shopping-assistant/lib/zorem-agent-bridge' ) ),
		);

		if ( '' !== $bridge && ! in_array( $bridge, $ours, true ) ) {
			$warnings[] = sprintf(
				/* translators: %s: folder of the loaded bridge copy. */
				__( 'The Agent Runtime Bridge in use is another plugin’s copy (%s), because it is the newest on this site. This plugin relies on its security code, so keep whichever plugin ships it up to date.', 'zorem-ai-agent-connector' ),
				$bridge
			);
		}

		return $warnings;
	}
}
