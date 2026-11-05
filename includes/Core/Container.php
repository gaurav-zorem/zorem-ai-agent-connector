<?php
/**
 * Service container.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A deliberately tiny service locator: explicit factories, lazy singletons.
 *
 * No autowiring and no reflection. Every service is registered by hand in
 * `Plugin::register_services()`, which makes that method the map of the whole
 * system. `get()` returns `mixed`, so call sites annotate the result with a
 * one-line `/** @var Service $var *\/`.
 */
final class Container {

	/**
	 * Factories by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $factories = array();

	/**
	 * Built instances by service id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Register a factory. Replacing one drops any instance it already built.
	 *
	 * @param string   $id      Service id, conventionally the class name.
	 * @param callable $factory Receives the container, returns the service.
	 */
	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an already-built instance.
	 *
	 * @param string $id       Service id.
	 * @param mixed  $instance The instance.
	 */
	public function instance( string $id, $instance ): void {
		$this->instances[ $id ] = $instance;
	}

	/**
	 * Resolve a service, building it on first use.
	 *
	 * @param string $id Service id.
	 * @return mixed
	 * @throws \RuntimeException When the id is not registered.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->instances ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \RuntimeException(
				sprintf( 'Service "%s" is not registered in the container.', esc_html( $id ) )
			);
		}

		$this->instances[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->instances[ $id ];
	}

	/**
	 * Whether a service is registered.
	 *
	 * @param string $id Service id.
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->instances );
	}
}
