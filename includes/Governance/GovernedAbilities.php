<?php
/**
 * Which abilities run through the gate.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which abilities AbilityGate wrapped, so the agent is refused any
 * ability it did not.
 *
 * The gate wraps an ability's callbacks as it registers. Three things get an
 * ability past that unwrapped: it was registered before the gate hooked in
 * (some code read the abilities registry before plugins_loaded priority 5),
 * its custom `ability_class` overrides the methods that call the callbacks,
 * or it has no callback to wrap. Each of those would run with no masking, no
 * activity row and no gating of the calls it makes, so for the agent it is
 * treated as not there. Humans are unaffected.
 *
 * One case of "no callback" is recoverable: a custom `ability_class` that
 * fills in its execute callback while it is being built (WooCommerce 11.3's
 * `ActionableAbility`, behind `woocommerce/product-create` and
 * `woocommerce/product-update`), and so arrives at the gate with none. When
 * that class leaves the calling methods alone, the gate wraps the callback
 * on the built object instead (`AbilityGate::adopt_deferred()`) and reports it
 * here with adopt(). Until then it is refused like any other.
 */
final class GovernedAbilities {

	/**
	 * The WP_Ability methods that run the callbacks the gate wraps.
	 */
	private const CALLING_METHODS = array( 'check_permissions', 'execute', 'do_execute', 'invoke_callback' );

	/**
	 * Abilities the gate wrapped, name => true.
	 *
	 * @var array<string, bool>
	 */
	private array $wrapped = array();

	/**
	 * Abilities whose class supplies the execute callback once built, name => class.
	 *
	 * @var array<string, string>
	 */
	private array $deferred = array();

	/**
	 * Abilities registered past the gate, name => reason.
	 *
	 * @var array<string, string>
	 */
	private array $ungoverned = array();

	/**
	 * Whether the abilities registry had already started when the gate hooked in.
	 *
	 * @var bool
	 */
	private bool $late = false;

	/**
	 * Record an ability the gate wrapped, or why it could not.
	 *
	 * @param string               $name Ability name.
	 * @param array<string, mixed> $args Its registration arguments, after wrapping.
	 */
	public function record( string $name, array $args ): void {
		$reason = self::why_ungoverned( $args );

		unset( $this->deferred[ $name ] );

		if ( null === $reason ) {
			$this->wrapped[ $name ] = true;
			unset( $this->ungoverned[ $name ] );

			return;
		}

		$this->ungoverned[ $name ] = $reason;
		unset( $this->wrapped[ $name ] );

		if ( self::builds_its_own_execute( $args ) ) {
			$this->deferred[ $name ] = (string) $args['ability_class'];
		}
	}

	/**
	 * Abilities to wrap once built, name => the class they were registered with.
	 *
	 * @return array<string, string>
	 */
	public function deferred(): array {
		return $this->deferred;
	}

	/**
	 * Record that the gate wrapped a deferred ability on the built object.
	 *
	 * @param string $name Ability name.
	 */
	public function adopt( string $name ): void {
		if ( ! isset( $this->deferred[ $name ] ) ) {
			return;
		}

		unset( $this->deferred[ $name ], $this->ungoverned[ $name ] );
		$this->wrapped[ $name ] = true;
	}

	/**
	 * Whether an ability with no execute callback may still be wrapped once built.
	 *
	 * It has a permission callback (already wrapped), no execute callback, and a
	 * custom `ability_class` that extends WP_Ability without overriding any
	 * method that calls the callbacks, so the callback its constructor fills in
	 * is the one WP_Ability will run.
	 *
	 * @param array<string, mixed> $args Registration arguments, after wrapping.
	 */
	private static function builds_its_own_execute( array $args ): bool {
		if ( empty( $args['permission_callback'] ) || ! empty( $args['execute_callback'] ) ) {
			return false;
		}

		$class = $args['ability_class'] ?? null;

		if ( ! is_string( $class ) || '' === $class || \WP_Ability::class === $class || ! class_exists( $class ) || ! is_subclass_of( $class, \WP_Ability::class ) ) {
			return false;
		}

		return ! self::overrides_calling_methods( $class );
	}

	/**
	 * Whether a WP_Ability subclass overrides a method that calls the callbacks.
	 *
	 * @param string $class_name Class name.
	 */
	private static function overrides_calling_methods( string $class_name ): bool {
		foreach ( self::CALLING_METHODS as $method ) {
			if ( method_exists( $class_name, $method ) && ( new \ReflectionMethod( $class_name, $method ) )->getDeclaringClass()->getName() !== \WP_Ability::class ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Note that the registry started before the gate hooked in.
	 */
	public function mark_late(): void {
		$this->late = true;
	}

	/**
	 * Whether the agent may be offered this ability at all.
	 *
	 * @param string $name Ability name.
	 */
	public function is_governed( string $name ): bool {
		return isset( $this->wrapped[ $name ] );
	}

	/**
	 * Abilities registered past the gate, name => reason, for Diagnostics.
	 *
	 * Reasons: `ability_class` (a custom class overrides the calling methods)
	 * or `no_callback`. When the registry started early, every ability then
	 * registered is missing from the wrapped set and `late()` is true.
	 *
	 * @return array<string, string>
	 */
	public function ungoverned(): array {
		return $this->ungoverned;
	}

	/**
	 * Whether the abilities registry started before the gate hooked in.
	 */
	public function late(): bool {
		return $this->late;
	}

	/**
	 * Why these registration arguments would run past the gate, or null.
	 *
	 * @param array<string, mixed> $args Registration arguments.
	 */
	private static function why_ungoverned( array $args ): ?string {
		if ( empty( $args['permission_callback'] ) || empty( $args['execute_callback'] ) ) {
			return 'no_callback';
		}

		$class = $args['ability_class'] ?? null;

		if ( ! is_string( $class ) || '' === $class || \WP_Ability::class === $class || ! class_exists( $class ) ) {
			return null;
		}

		return self::overrides_calling_methods( $class ) ? 'ability_class' : null;
	}
}
