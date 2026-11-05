<?php
/**
 * What each installed Agent OS component provides.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Registry;

use ZoremAgentBridge\V1\Dto\FeatureInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Capability discovery by name.
 *
 * A plugin asks `has( 'agent-os.core', '0.2.0' )` instead of probing another
 * plugin's classes or constants, so the answer survives the other plugin
 * renaming anything internal. The first announcement of an id wins; a second
 * one is recorded as a duplicate and ignored. Registration closes when the
 * registry seals, as with providers.
 */
final class FeatureRegistry {

	/**
	 * Features by id.
	 *
	 * @var array<string, FeatureInfo>
	 */
	private array $features = array();

	/**
	 * Refused duplicate announcements: "id by owner".
	 *
	 * @var array<int, string>
	 */
	private array $duplicates = array();

	/**
	 * Whether announcements are closed.
	 *
	 * @var bool
	 */
	private bool $sealed = false;

	/**
	 * Announce a feature.
	 *
	 * @param string $id      Feature id, e.g. "agent-os.core".
	 * @param string $version Version.
	 * @param string $owner   Slug of the announcing plugin.
	 * @return bool Whether the announcement was accepted.
	 */
	public function register( string $id, string $version, string $owner ): bool {
		$feature = new FeatureInfo( $id, $version, $owner );

		if ( $this->sealed || isset( $this->features[ $id ] ) ) {
			$this->duplicates[] = $id . ' by ' . $owner;

			return false;
		}

		$this->features[ $id ] = $feature;

		return true;
	}

	/**
	 * Close announcements.
	 */
	public function seal(): void {
		$this->sealed = true;
	}

	/**
	 * Whether a feature is present, optionally at or above a version within its major.
	 *
	 * @param string $id      Feature id.
	 * @param string $minimum Lowest acceptable version; empty accepts any.
	 */
	public function has( string $id, string $minimum = '' ): bool {
		if ( ! isset( $this->features[ $id ] ) ) {
			return false;
		}

		if ( '' === $minimum ) {
			return true;
		}

		$version = $this->features[ $id ]->version();
		$major   = (int) $minimum;

		return version_compare( $version, $minimum, '>=' ) && version_compare( $version, ( $major + 1 ) . '.0.0-dev', '<' );
	}

	/**
	 * One feature, or null.
	 *
	 * @param string $id Feature id.
	 */
	public function get( string $id ): ?FeatureInfo {
		return $this->features[ $id ] ?? null;
	}

	/**
	 * Every feature, by id.
	 *
	 * @return array<string, FeatureInfo>
	 */
	public function all(): array {
		return $this->features;
	}

	/**
	 * Refused duplicate announcements.
	 *
	 * @return array<int, string>
	 */
	public function duplicates(): array {
		return $this->duplicates;
	}
}
