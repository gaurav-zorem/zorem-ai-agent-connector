<?php
/**
 * Writes knowledge out in the registry format.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Curated;

use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns stored entries into `.md` files and a catalog.
 *
 * The output is input for a person. Extraction gives a curator a first draft
 * with the facts already assembled and correctly attributed; they edit the
 * prose, delete what is noise, and open a pull request. Everything the format
 * does — front matter for fields, Markdown for the body, a fenced JSON block
 * for facts — exists to make that edit and its diff readable.
 *
 * What is exported is what the *site* knows, which is not the same as what an
 * agent may read: superseded and draft entries are excluded, because a curator
 * starting from an unreviewed draft would be curating a guess.
 */
final class RegistryExporter {

	/**
	 * Schema version, matching the registry layout.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Knowledge storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Knowledge storage.
	 */
	public function __construct( KnowledgeRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Export to a directory.
	 *
	 * @param string $root   Target directory.
	 * @param string $plugin Restrict to one plugin, or empty for all.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function export( string $root, string $plugin = '' ) {
		$root = rtrim( str_replace( '\\', '/', $root ), '/' ) . '/' . self::SCHEMA_VERSION;

		if ( ! wp_mkdir_p( $root ) ) {
			return new \WP_Error(
				'zorem_agent_brain_export_mkdir_failed',
				sprintf(
					/* translators: %s: directory path. */
					__( 'Could not create the export directory %s.', 'zorem-ai-agent-connector' ),
					$root
				)
			);
		}

		$args = array(
			'per_page'     => 500,
			'trust_status' => KnowledgeEntry::TRUST_APPROVED,
		);

		if ( '' !== $plugin ) {
			$args['plugin_slug'] = $plugin;
		}

		$entries = $this->repository->query( $args )['items'];

		$catalog = array();
		$files   = array();

		foreach ( $entries as $entry ) {
			$slug = (string) $entry->get_plugin_slug();

			if ( '' === $slug ) {
				continue;
			}

			$relative = $slug . '/' . $this->file_name( $entry ) . '.md';
			$path     = $root . '/' . $relative;

			if ( ! wp_mkdir_p( dirname( $path ) ) ) {
				continue;
			}

			$document = $this->render( $entry );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing an export to a path the operator chose.
			if ( false === file_put_contents( $path, $document ) ) {
				continue;
			}

			$catalog[ $slug ][] = array(
				'file'     => $relative,
				'checksum' => md5( $document ),
			);

			$files[] = $relative;
		}

		$index = $this->catalog( $catalog, $entries );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- See above.
		file_put_contents( $root . '/index.json', $index );

		$files[] = 'index.json';

		return array(
			'root'    => $root,
			'entries' => count( $files ) - 1,
			'plugins' => count( $catalog ),
			'files'   => $files,
		);
	}

	/**
	 * Render one entry as a registry document.
	 *
	 * @param KnowledgeEntry $entry The entry.
	 */
	public function render( KnowledgeEntry $entry ): string {
		$fields = array(
			'entry_key' => $entry->get_entry_key(),
			'section'   => $entry->get_section(),
			'title'     => $entry->get_title(),
		);

		if ( '' !== $entry->get_summary() ) {
			$fields['summary'] = $entry->get_summary();
		}

		$version = (string) $entry->get_plugin_version();

		if ( '' !== $version ) {
			/*
			 * A range rather than a pinned version, because that is what a
			 * curator almost always wants and getting it wrong the other way is
			 * silent: a pinned entry simply stops applying after the next
			 * update, and nobody notices the knowledge went missing.
			 */
			$fields['plugin_min_version'] = $version;
			$fields['plugin_max_version'] = $this->open_upper_bound( $version );
		}

		$document = "---\n";

		foreach ( $fields as $key => $value ) {
			$document .= $key . ': ' . $this->scalar( (string) $value ) . "\n";
		}

		$document .= "---\n\n" . rtrim( $entry->get_content() ) . "\n";

		$facts = $entry->get_facts();

		if ( array() !== $facts ) {
			$document .= "\n## Facts\n\n```json\n"
				. (string) wp_json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
				. "\n```\n";
		}

		return $document;
	}

	/**
	 * Quote a front-matter value when it needs it.
	 *
	 * @param string $value Raw value.
	 */
	private function scalar( string $value ): string {
		$value = trim( str_replace( array( "\r", "\n" ), ' ', $value ) );

		if ( '' === $value ) {
			return '""';
		}

		/*
		 * Quoting is decided by what the parser would do with the value, not by
		 * taste: anything it would refuse bare gets quoted, and a value
		 * containing a double quote gets single quotes. A value containing both
		 * cannot be represented, so the inner double quotes are dropped rather
		 * than writing a file our own parser rejects.
		 */
		$needs_quotes = (bool) preg_match( '/^[\[\{\&\*\!\|\>\%\@\`]/', $value )
			|| false !== strpos( $value, ': ' )
			|| false !== strpos( $value, '#' );

		if ( ! $needs_quotes ) {
			return $value;
		}

		if ( false === strpos( $value, '"' ) ) {
			return '"' . $value . '"';
		}

		if ( false === strpos( $value, "'" ) ) {
			return "'" . $value . "'";
		}

		return '"' . str_replace( '"', '', $value ) . '"';
	}

	/**
	 * A file name for an entry.
	 *
	 * @param KnowledgeEntry $entry The entry.
	 */
	private function file_name( KnowledgeEntry $entry ): string {
		$key = $entry->get_entry_key();

		// Drop the plugin prefix; the directory already carries it.
		$tail = preg_replace( '/^plugin:' . preg_quote( (string) $entry->get_plugin_slug(), '/' ) . ':/', '', $key );
		$name = strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $tail ) ?? '' );

		$trimmed = trim( $name, '-' );

		return '' === $trimmed ? 'entry' : $trimmed;
	}

	/**
	 * Build the catalog document.
	 *
	 * @param array<string, array<int, array<string, string>>> $catalog Files per plugin.
	 * @param array<int, KnowledgeEntry>                       $entries Exported entries.
	 */
	private function catalog( array $catalog, array $entries ): string {
		$versions = array();

		foreach ( $entries as $entry ) {
			$slug = (string) $entry->get_plugin_slug();

			if ( '' !== $slug && '' !== (string) $entry->get_plugin_version() ) {
				$versions[ $slug ] = (string) $entry->get_plugin_version();
			}
		}

		$plugins = array();

		foreach ( $catalog as $slug => $files ) {
			$version = $versions[ $slug ] ?? '';

			$plugins[ $slug ] = array(
				'ranges' => array(
					array(
						'min'     => $version,
						'max'     => '' === $version ? '' : $this->open_upper_bound( $version ),
						'entries' => $files,
					),
				),
			);
		}

		return (string) wp_json_encode(
			array(
				'schema_version' => self::SCHEMA_VERSION,
				'generated_by'   => 'zorem-agent-brain ' . \ZoremAgentBrain\V1\Brain::version(),
				'generated_at'   => gmdate( 'c' ),
				'plugins'        => $plugins,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	}

	/**
	 * An upper bound covering the rest of the major series.
	 *
	 * @param string $version Installed version.
	 */
	private function open_upper_bound( string $version ): string {
		$major = (int) strtok( $version, '.' );

		return $major . '.99';
	}
}
