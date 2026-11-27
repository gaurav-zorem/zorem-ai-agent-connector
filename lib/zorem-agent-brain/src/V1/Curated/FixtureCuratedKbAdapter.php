<?php
/**
 * A curated source backed by a registry tree on disk.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a curated registry from the filesystem.
 *
 * This is the registry the kit bundles. A cloud catalog is out of scope, but the format
 * and every rule around it are real: the same parser, the same catalog, the same
 * whole-entry rejection, the same precedence. When the HTTP adapter arrives it
 * changes where the bytes come from and nothing else — which is the point of
 * building the local one first.
 *
 * Failures are absorbed here rather than reported upward, per the interface
 * contract. A missing file, an unreadable directory or a malformed entry
 * results in that entry being absent, with the reason logged; the caller sees
 * a source with less in it, never an error to handle.
 */
final class FixtureCuratedKbAdapter implements CuratedKbAdapterInterface {

	/**
	 * Registry root, containing index.json.
	 *
	 * @var string
	 */
	private string $root;

	/**
	 * Source identifier.
	 *
	 * @var string
	 */
	private string $source_id;

	/**
	 * Parser.
	 *
	 * @var RegistryParser
	 */
	private RegistryParser $parser;

	/**
	 * Reasons entries were rejected, for diagnostics.
	 *
	 * @var array<int, string>
	 */
	private array $rejections = array();

	/**
	 * Cached catalog.
	 *
	 * @var CatalogIndex|null
	 */
	private ?CatalogIndex $catalog = null;

	/**
	 * Constructor.
	 *
	 * @param string              $root      Registry root directory.
	 * @param string              $source_id Source identifier.
	 * @param RegistryParser|null $parser    Parser.
	 */
	public function __construct( string $root, string $source_id = 'zorem-fixture', ?RegistryParser $parser = null ) {
		$this->root      = rtrim( str_replace( '\\', '/', $root ), '/' );
		$this->source_id = $source_id;
		$this->parser    = $parser ?? new RegistryParser();
	}

	/**
	 * Source identifier.
	 */
	public function get_source_id(): string {
		return $this->source_id;
	}

	/**
	 * Whether this source covers a plugin version.
	 *
	 * @param string $plugin_slug    Plugin slug.
	 * @param string $plugin_version Installed version.
	 */
	public function supports( string $plugin_slug, string $plugin_version ): bool {
		$catalog = $this->catalog();

		return null !== $catalog && $catalog->supports( $plugin_slug, $plugin_version );
	}

	/**
	 * Which of the offered slugs this source covers.
	 *
	 * @param array<int, string> $slugs Candidate slugs.
	 * @return array<int, string>
	 */
	public function covered_slugs( array $slugs ): array {
		$catalog = $this->catalog();

		if ( null === $catalog ) {
			return array();
		}

		return array_values( array_intersect( $slugs, $catalog->slugs() ) );
	}

	/**
	 * The entries this source has for a plugin.
	 *
	 * @param string $plugin_slug    Plugin slug.
	 * @param string $plugin_version Installed version.
	 * @return array<int, array<string, mixed>>
	 */
	public function fetch( string $plugin_slug, string $plugin_version ) {
		$catalog = $this->catalog();

		if ( null === $catalog ) {
			return array();
		}

		$this->rejections = array();
		$entries          = array();

		foreach ( $catalog->entries_for( $plugin_slug, $plugin_version ) as $descriptor ) {
			$path = $this->root . '/' . $descriptor['file'];

			// Belt and braces: the catalog already refused unsafe paths.
			if ( ! CatalogIndex::is_safe_relative_path( $descriptor['file'] ) || ! is_readable( $path ) ) {
				$this->reject( $descriptor['file'], __( 'file missing or unreadable', 'zorem-ai-agent-connector' ) );
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local registry file, not a URL.
			$source = (string) file_get_contents( $path );

			if ( '' !== $descriptor['checksum'] && md5( $source ) !== $descriptor['checksum'] ) {
				/*
				 * The catalog and the file disagree. That is either a corrupted
				 * download or a file edited without regenerating the catalog;
				 * either way the entry is not what the catalog vouches for, so
				 * it is refused rather than trusted.
				 */
				$this->reject( $descriptor['file'], __( 'checksum does not match the catalog', 'zorem-ai-agent-connector' ) );
				continue;
			}

			$parsed = $this->parser->parse( $source, $plugin_slug, $this->source_id );

			if ( is_wp_error( $parsed ) ) {
				$this->reject( $descriptor['file'], $parsed->get_error_message() );
				continue;
			}

			$entries[] = $parsed;
		}

		return $entries;
	}

	/**
	 * Why entries were refused during the last fetch.
	 *
	 * @return array<int, string>
	 */
	public function rejections(): array {
		return $this->rejections;
	}

	/**
	 * The catalog, read once.
	 */
	private function catalog(): ?CatalogIndex {
		if ( null !== $this->catalog ) {
			return $this->catalog;
		}

		$path = $this->root . '/index.json';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local registry file, not a URL.
		$catalog = CatalogIndex::from_json( (string) file_get_contents( $path ) );

		if ( is_wp_error( $catalog ) ) {
			$this->reject( 'index.json', $catalog->get_error_message() );

			return null;
		}

		$this->catalog = $catalog;

		return $this->catalog;
	}

	/**
	 * Record a refusal.
	 *
	 * @param string $file   File that was refused.
	 * @param string $reason Why.
	 */
	private function reject( string $file, string $reason ): void {
		$this->rejections[] = $file . ': ' . $reason;
	}
}
