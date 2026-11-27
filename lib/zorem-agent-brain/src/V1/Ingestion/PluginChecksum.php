<?php
/**
 * Identifies a plugin's current state cheaply.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Ingestion;

use ZoremAgentBrain\V1\Extraction\PluginLocator;

defined( 'ABSPATH' ) || exit;

/**
 * A fingerprint deciding whether a plugin needs re-reading.
 *
 * Version alone is not enough. A plugin updated over FTP, checked out from Git,
 * or edited in place by a developer keeps its version string while its code
 * changes completely — and a Brain describing last month's code with today's
 * version number is confidently wrong, which is the failure mode this whole
 * phase is built to avoid.
 *
 * So the fingerprint also covers the main file's size and modification time.
 * Both are stat calls, not reads, so this stays cheap enough to run for every
 * active plugin on every tick. It is not a content hash: hashing 24 MB of
 * WooCommerce to decide whether to read 24 MB of WooCommerce saves nothing.
 *
 * The trade-off is stated rather than hidden: an edit that preserves size and
 * mtime is invisible here. That is a deliberate touch-file case, not something
 * a normal update or deploy produces.
 */
final class PluginChecksum {

	/**
	 * Fingerprint one plugin.
	 *
	 * @param string               $slug Plugin slug.
	 * @param array<string, mixed> $data Plugin header data from the locator.
	 */
	public function for_plugin( string $slug, array $data = array() ): string {
		$file = (string) ( $data['file'] ?? '' );
		$path = '' !== $file ? PluginLocator::plugins_dir() . '/' . $file : '';

		$size  = 0;
		$mtime = 0;

		if ( '' !== $path && is_readable( $path ) ) {
			$size  = (int) @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file fingerprints as zero, which forces a re-read.
			$mtime = (int) @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Same.
		}

		return md5(
			implode(
				'|',
				array(
					$slug,
					(string) ( $data['Version'] ?? '' ),
					(string) ( $data['TextDomain'] ?? '' ),
					(string) $size,
					(string) $mtime,
				)
			)
		);
	}

	/**
	 * Fingerprint many plugins.
	 *
	 * @param array<string, array<string, mixed>> $plugins Slug => plugin data.
	 * @return array<string, string>
	 */
	public function for_plugins( array $plugins ): array {
		$checksums = array();

		foreach ( $plugins as $slug => $data ) {
			$checksums[ (string) $slug ] = $this->for_plugin( (string) $slug, (array) $data );
		}

		return $checksums;
	}
}
