<?php
/**
 * A discoverable feature.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Something a plugin announces it provides, so others can ask for it by name
 * rather than probing classes: e.g. "agent-os.core" at "0.2.0".
 */
final class FeatureInfo {

	/**
	 * Feature id, dotted lowercase.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Slug of the announcing plugin.
	 *
	 * @var string
	 */
	private string $owner;

	/**
	 * Constructor.
	 *
	 * @param string $id      Feature id.
	 * @param string $version Version.
	 * @param string $owner   Slug of the announcing plugin.
	 * @throws \InvalidArgumentException When the id is malformed or a field is empty.
	 */
	public function __construct( string $id, string $version, string $owner ) {
		if ( 1 !== preg_match( '/^[a-z0-9-]+(\.[a-z0-9_-]+)+$/', $id ) ) {
			throw new \InvalidArgumentException( 'A feature id must be dotted lowercase, e.g. "agent-os.core".' );
		}

		if ( '' === $version || '' === $owner ) {
			throw new \InvalidArgumentException( 'A feature needs a version and an owner.' );
		}

		$this->id      = $id;
		$this->version = $version;
		$this->owner   = $owner;
	}

	/**
	 * Feature id.
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Version.
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Slug of the announcing plugin.
	 */
	public function owner(): string {
		return $this->owner;
	}
}
