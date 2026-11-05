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
 * Remembers which abilities AbilityGate wrapped, so an agent is refused any
 * ability it did not.
 *
 * The gate wraps an ability's callbacks as it registers. Three things get an
 * ability past that unwrapped: it was registered before the gate hooked in
 * (some code read the abilities registry before plugins_loaded priority 5),
 * its custom `ability_class` overrides the methods that call the callbacks,
 * or it has no callback to wrap. Each of those would run with no masking, no
 * activity row and no gating of the calls it makes, so for an agent it is
 * treated as not there. Humans are unaffected.
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

		if ( null === $reason ) {
			$this->wrapped[ $name ] = true;
			unset( $this->ungoverned[ $name ] );

			return;
		}

		$this->ungoverned[ $name ] = $reason;
		unset( $this->wrapped[ $name ] );
	}

	/**
	 * Note that the registry started before the gate hooked in.
	 */
	public function mark_late(): void {
		$this->late = true;
	}

	/**
	 * Whether an agent may be offered this ability at all.
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

		foreach ( self::CALLING_METHODS as $method ) {
			if ( method_exists( $class, $method ) && ( new \ReflectionMethod( $class, $method ) )->getDeclaringClass()->getName() !== \WP_Ability::class ) {
				return 'ability_class';
			}
		}

		return null;
	}
}
