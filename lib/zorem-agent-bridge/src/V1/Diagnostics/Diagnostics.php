<?php
/**
 * What the bridge knows about this site, for people.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Diagnostics;

use ZoremAgentBridge\V1\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * A report of the loaded bridge, its providers, features and problems.
 *
 * Either plugin renders the panel on its status screen. The conflict notice is
 * rendered by the bridge itself, once, so a site with both plugins does not
 * show the same warning twice.
 */
final class Diagnostics {

	/**
	 * The report as data.
	 *
	 * @return array{version: string, copies: array<string, string>, providers: array<string, string>, conflicts: array<string, array<int, string>>, late_claims: array<int, string>, features: array<string, string>}
	 */
	public static function report(): array {
		$features = array();

		foreach ( Bridge::features()->all() as $id => $feature ) {
			$features[ $id ] = $feature->version() . ' (' . $feature->owner() . ')';
		}

		$providers = array();

		foreach ( Bridge::registry()->owners() as $contract => $owner ) {
			$providers[ self::short( $contract ) ] = $owner;
		}

		$conflicts = array();

		foreach ( Bridge::registry()->conflicts() as $contract => $owners ) {
			$conflicts[ self::short( $contract ) ] = $owners;
		}

		return array(
			'version'     => Bridge::version(),
			'copies'      => Bridge::copies(),
			'providers'   => $providers,
			'conflicts'   => $conflicts,
			'late_claims' => Bridge::registry()->late_claims(),
			'features'    => $features,
		);
	}

	/**
	 * The report as an escaped HTML definition list.
	 */
	public static function render_panel(): string {
		$report = self::report();
		$rows   = array(
			__( 'Bridge version', 'zorem-ai-agent-connector' ) => $report['version'],
			__( 'Bundled copies', 'zorem-ai-agent-connector' ) => (string) count( $report['copies'] ),
			__( 'Features', 'zorem-ai-agent-connector' )       => self::join( $report['features'] ),
			__( 'Providers', 'zorem-ai-agent-connector' )      => self::join( $report['providers'] ),
			__( 'Provider conflicts', 'zorem-ai-agent-connector' ) => self::join( array_map( static fn ( array $owners ): string => implode( ', ', $owners ), $report['conflicts'] ) ),
		);

		$html = '<dl class="zorem-agent-bridge-diagnostics">';

		foreach ( $rows as $label => $value ) {
			$html .= '<div><dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( '' !== $value ? $value : __( 'None', 'zorem-ai-agent-connector' ) ) . '</dd></div>';
		}

		return $html . '</dl>';
	}

	/**
	 * Render the admin notice for provider conflicts, when there are any.
	 */
	public static function render_conflict_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$conflicts = Bridge::registry()->conflicts();

		if ( array() === $conflicts ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Agent OS: conflicting providers', 'zorem-ai-agent-connector' ) . '</strong></p><ul>';

		foreach ( $conflicts as $contract => $owners ) {
			$message = sprintf(
				/* translators: 1: contract name, 2: comma-separated plugin slugs. */
				__( '%1$s was claimed by more than one plugin (%2$s), so none of them provides it and the safe default is in force. Deactivate the plugin that should not provide it.', 'zorem-ai-agent-connector' ),
				self::short( $contract ),
				implode( ', ', $owners )
			);

			echo '<li>' . esc_html( $message ) . ' <code>provider_conflict</code></li>';
		}

		echo '</ul></div>';
	}

	/**
	 * The interface name without its namespace.
	 *
	 * @param string $contract Fully qualified interface name.
	 */
	private static function short( string $contract ): string {
		$position = strrpos( $contract, '\\' );

		return false === $position ? $contract : substr( $contract, $position + 1 );
	}

	/**
	 * "key: value" pairs, comma-separated.
	 *
	 * @param array<string, string> $pairs Key => value.
	 */
	private static function join( array $pairs ): string {
		$parts = array();

		foreach ( $pairs as $key => $value ) {
			$parts[] = $key . ': ' . $value;
		}

		return implode( ', ', $parts );
	}
}
