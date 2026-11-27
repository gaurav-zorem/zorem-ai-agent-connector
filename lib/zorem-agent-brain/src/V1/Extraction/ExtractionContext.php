<?php
/**
 * Everything an extractor knows about one plugin.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * The shared input to every extractor for a single plugin.
 *
 * Assembled once per plugin and passed to all eight extractors, so the
 * expensive parts — the token scan, the runtime attribution pass — happen once
 * rather than eight times. Immutable: an extractor reads it and returns
 * entries, it never mutates shared state.
 */
final class ExtractionContext {

	/**
	 * Plugin slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Plugin header data from get_plugin_data().
	 *
	 * @var array<string, mixed>
	 */
	private array $header;

	/**
	 * Static scan results for this plugin.
	 *
	 * @var array<string, mixed>
	 */
	private array $scan;

	/**
	 * Runtime artefacts attributed to this plugin.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $runtime;

	/**
	 * Reconciliation verdicts, group => name => {confidence, evidence}.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $reconciled;

	/**
	 * Constructor.
	 *
	 * @param string                                              $slug       Plugin slug.
	 * @param array<string, mixed>                                $header     Plugin header data.
	 * @param array<string, mixed>                                $scan       Static scan results.
	 * @param array<string, array<int, string>>                   $runtime    Runtime artefacts.
	 * @param array<string, array<string, array<string, string>>> $reconciled Reconciliation result.
	 */
	public function __construct(
		string $slug,
		array $header = array(),
		array $scan = array(),
		array $runtime = array(),
		array $reconciled = array()
	) {
		$this->slug       = $slug;
		$this->header     = $header;
		$this->scan       = $scan;
		$this->runtime    = $runtime;
		$this->reconciled = $reconciled;
	}

	/**
	 * Plugin slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Plugin version, or an empty string.
	 */
	public function version(): string {
		return (string) ( $this->header['Version'] ?? '' );
	}

	/**
	 * Plugin display name, falling back to the slug.
	 */
	public function name(): string {
		$name = trim( (string) ( $this->header['Name'] ?? '' ) );

		return '' === $name ? $this->slug : $name;
	}

	/**
	 * All plugin header data.
	 *
	 * @return array<string, mixed>
	 */
	public function header(): array {
		return $this->header;
	}

	/**
	 * One header field.
	 *
	 * @param string $key     Header key.
	 * @param string $default_value Returned when absent.
	 */
	public function header_field( string $key, string $default_value = '' ): string {
		$value = trim( (string) ( $this->header[ $key ] ?? '' ) );

		return '' === $value ? $default_value : $value;
	}

	/**
	 * The plugin's directory.
	 */
	public function directory(): string {
		return PluginLocator::path_for( $this->slug );
	}

	/**
	 * Static scan results.
	 *
	 * @return array<string, mixed>
	 */
	public function scan(): array {
		return $this->scan;
	}

	/**
	 * Names found by the static scan for one fact group.
	 *
	 * @param string $group Fact group.
	 * @return array<int, string>
	 */
	public function declared( string $group ): array {
		$calls = isset( $this->scan['calls'] ) && is_array( $this->scan['calls'] ) ? $this->scan['calls'] : array();

		return isset( $calls[ $group ] ) && is_array( $calls[ $group ] ) ? $calls[ $group ] : array();
	}

	/**
	 * Names found at runtime for one fact group.
	 *
	 * @param string $group Fact group.
	 * @return array<int, string>
	 */
	public function live( string $group ): array {
		return isset( $this->runtime[ $group ] ) && is_array( $this->runtime[ $group ] )
			? $this->runtime[ $group ]
			: array();
	}

	/**
	 * Every name in a group, from either pass.
	 *
	 * @param string $group Fact group.
	 * @return array<int, string>
	 */
	public function all_names( string $group ): array {
		$names = array_unique( array_merge( $this->live( $group ), $this->declared( $group ) ) );

		sort( $names );

		return array_values( $names );
	}

	/**
	 * The reconciliation confidence for one artefact.
	 *
	 * @param string $group Fact group.
	 * @param string $name  Artefact name.
	 */
	public function confidence( string $group, string $name ): string {
		$verdict = $this->reconciled[ $group ][ $name ] ?? null;

		if ( is_array( $verdict ) ) {
			return (string) ( $verdict['confidence'] ?? PluginAttributionResolver::DECLARED_ONLY );
		}

		return PluginAttributionResolver::DECLARED_ONLY;
	}

	/**
	 * How an artefact's attribution was arrived at.
	 *
	 * Recorded alongside confidence rather than folded into it: two artefacts
	 * can both be `confident` because one was watched registering itself and the
	 * other was inferred twice with matching results, and a reader deciding how
	 * far to trust an entry should be able to tell those apart.
	 *
	 * @param string $group Fact group.
	 * @param string $name  Artefact name.
	 */
	public function evidence( string $group, string $name ): string {
		$verdict = $this->reconciled[ $group ][ $name ] ?? null;

		if ( is_array( $verdict ) ) {
			return (string) ( $verdict['evidence'] ?? PluginAttributionResolver::EVIDENCE_DECLARED );
		}

		return PluginAttributionResolver::EVIDENCE_DECLARED;
	}

	/**
	 * Whether the static scan was cut short, and why.
	 */
	public function truncated(): ?string {
		if ( empty( $this->scan['truncated'] ) ) {
			return null;
		}

		return (string) ( $this->scan['stop_reason'] ?? 'budget' );
	}

	/**
	 * Metadata every entry from this plugin should carry.
	 *
	 * Truncation travels with the knowledge itself, not only with the log. An
	 * entry built from a partial scan needs to say so wherever it is read,
	 * because "no shortcodes found" and "we stopped looking" are different
	 * claims and only one of them is safe to act on.
	 *
	 * @return array<string, mixed>
	 */
	public function base_meta(): array {
		$meta = array();

		$truncated = $this->truncated();

		if ( null !== $truncated ) {
			$meta['truncated']     = true;
			$meta['truncated_why'] = $truncated;
			$meta['files_scanned'] = (int) ( $this->scan['files'] ?? 0 );
			$meta['files_found']   = (int) ( $this->scan['files_found'] ?? 0 );
		}

		return $meta;
	}
}
