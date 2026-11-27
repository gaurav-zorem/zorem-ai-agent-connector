<?php
/**
 * Which plugins use the shared Brain.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Host;

defined( 'ABSPATH' ) || exit;

/**
 * The record of every plugin that hosts the Brain on this site.
 *
 * Uninstall reads it to decide whether Brain data may be removed: only when no
 * *other* host remains installed (decision D-11). Two safeguards keep the
 * record honest:
 *
 * - A recorded host counts only while its plugin file still exists, so a host
 *   deleted without running its uninstaller cannot keep data alive forever.
 * - A known Agent OS plugin present on disk counts even when it is missing from
 *   the record, so a lost record cannot delete data another plugin still uses.
 *
 * Only the kit writes this option.
 */
final class HostRegistry {

	/** Option holding the hosts. */
	public const OPTION = 'zorem_agent_brain_hosts';

	/**
	 * Plugin basenames of every Agent OS plugin that can host the Brain.
	 *
	 * Checked on uninstall in addition to the record.
	 *
	 * @var array<string, string>
	 */
	public const KNOWN_HOSTS = array(
		'ai-agent-os'           => 'ai-agent-os-pro/ai-agent-os-pro.php',
		'ai-shopping-assistant' => 'ai-shopping-assistant/ai-shopping-assistant.php',
	);

	/**
	 * Directory plugins live in.
	 *
	 * @var string
	 */
	private string $plugins_dir;

	/**
	 * Constructor.
	 *
	 * @param string $plugins_dir Directory plugins live in; defaults to WP_PLUGIN_DIR.
	 */
	public function __construct( string $plugins_dir = '' ) {
		$this->plugins_dir = untrailingslashit( '' !== $plugins_dir ? $plugins_dir : WP_PLUGIN_DIR );
	}

	/**
	 * Record a host, or refresh its version.
	 *
	 * @param string $slug     Host plugin slug.
	 * @param string $version  Host plugin version.
	 * @param string $basename Host plugin basename, e.g. "ai-agent-os-pro/ai-agent-os-pro.php".
	 * @return bool True when the host was not recorded before.
	 */
	public function join( string $slug, string $version, string $basename ): bool {
		$hosts = $this->all();
		$new   = ! isset( $hosts[ $slug ] );

		$hosts[ $slug ] = array(
			'version'   => $version,
			'basename'  => $basename,
			'joined_at' => $new ? gmdate( 'Y-m-d H:i:s' ) : (string) ( $hosts[ $slug ]['joined_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);

		update_option( self::OPTION, $hosts, false );

		return $new;
	}

	/**
	 * Remove a host from the record.
	 *
	 * @param string $slug Host plugin slug.
	 */
	public function leave( string $slug ): void {
		$hosts = $this->all();

		unset( $hosts[ $slug ] );

		update_option( self::OPTION, $hosts, false );
	}

	/**
	 * Every recorded host, slug => record. Read from the option every time.
	 *
	 * @return array<string, array{version: string, basename: string, joined_at: string}>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether a host's plugin file is present on disk.
	 *
	 * @param string $basename Plugin basename.
	 */
	public function is_installed( string $basename ): bool {
		return '' !== $basename && file_exists( $this->plugins_dir . '/' . $basename );
	}

	/**
	 * Slugs of hosts other than the given one that are still installed.
	 *
	 * Considers every recorded host and every known Agent OS plugin, each under
	 * both its known and its recorded file, so neither a lost record nor a stale
	 * one (a plugin whose folder was renamed) can make a still-installed
	 * plugin's data look orphaned.
	 *
	 * @param string $excluding Slug of the host asking (usually the one uninstalling).
	 * @return array<int, string>
	 */
	public function other_installed_hosts( string $excluding ): array {
		$candidates = array();

		foreach ( self::KNOWN_HOSTS as $slug => $basename ) {
			$candidates[ $slug ][] = $basename;
		}

		foreach ( $this->all() as $slug => $record ) {
			$candidates[ $slug ][] = (string) ( $record['basename'] ?? '' );
		}

		unset( $candidates[ $excluding ] );

		$installed = array();

		foreach ( $candidates as $slug => $basenames ) {
			foreach ( array_unique( $basenames ) as $basename ) {
				if ( $this->is_installed( $basename ) ) {
					$installed[] = (string) $slug;
					break;
				}
			}
		}

		return $installed;
	}
}
