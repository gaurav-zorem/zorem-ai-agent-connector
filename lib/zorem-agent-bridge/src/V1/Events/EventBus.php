<?php
/**
 * Delivery of bridge events.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes catalogued events as WordPress actions, schema-checked.
 *
 * An unknown name or a missing payload key is a programming error and throws
 * where it is published — a malformed event delivered anyway would be read by
 * listeners that trust its shape.
 */
final class EventBus {

	/** Envelope version. */
	public const ENVELOPE_VERSION = 1;

	/**
	 * Publish an event.
	 *
	 * Listeners receive one array: `v`, `name`, `payload` and `emitted_at` (UTC).
	 *
	 * @param string               $name    One of the Events constants.
	 * @param array<string, mixed> $payload Payload, with at least the schema's keys.
	 * @throws \InvalidArgumentException When the name is unknown or a required key is missing.
	 */
	public function publish( string $name, array $payload ): void {
		$schemas = Events::schemas();

		if ( ! isset( $schemas[ $name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown bridge event "%s".', esc_html( $name ) ) );
		}

		foreach ( $schemas[ $name ] as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				throw new \InvalidArgumentException( sprintf( 'Event "%1$s" needs "%2$s".', esc_html( $name ), esc_html( $key ) ) );
			}
		}

		$envelope = array(
			'v'          => self::ENVELOPE_VERSION,
			'name'       => $name,
			'payload'    => $payload,
			'emitted_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Prefixed by Events::hook(): zorem_agent_bridge_v1_*.
		do_action( Events::hook( $name ), $envelope );
	}

	/**
	 * Listen to an event.
	 *
	 * @param string   $name     One of the Events constants.
	 * @param callable $listener Receives the envelope array.
	 * @param int      $priority Hook priority.
	 * @throws \InvalidArgumentException When the name is unknown.
	 */
	public function subscribe( string $name, callable $listener, int $priority = 10 ): void {
		if ( ! isset( Events::schemas()[ $name ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown bridge event "%s".', esc_html( $name ) ) );
		}

		add_action( Events::hook( $name ), $listener, $priority, 1 );
	}
}
