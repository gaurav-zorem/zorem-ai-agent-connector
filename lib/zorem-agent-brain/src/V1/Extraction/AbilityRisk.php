<?php
/**
 * How risky an ability is, for documentation.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies an ability as read, write or destructive, for the abilities section.
 *
 * Ported from the legacy plugin's RiskClassifier ladder, without the admin
 * overrides: those belong to governance, which is AI Agent OS's. Core feeds
 * its own classification in through `zorem_agent_brain_ability_risk`, so with
 * Core installed the Brain documents the same risk the gate enforces; without
 * it, the Brain documents what the ability declares, or a safe guess marked as
 * one.
 *
 * This is documentation, never enforcement. Nothing here grants or refuses a
 * call.
 */
final class AbilityRisk {

	public const RISK_READ        = 'read';
	public const RISK_WRITE       = 'write';
	public const RISK_DESTRUCTIVE = 'destructive';

	/** Grant levels, as governance names them. */
	public const LEVEL_NONE  = 'none';
	public const LEVEL_READ  = 'read';
	public const LEVEL_WRITE = 'write';

	/**
	 * Name fragments implying a read-only ability.
	 */
	private const READ_PATTERNS = array(
		'-query',
		'-get',
		'-list',
		'-read',
		'-search',
		'-find',
		'-info',
		'-status',
		'-report',
		'-preview',
		'discover-',
	);

	/**
	 * Name fragments implying an irreversible ability.
	 */
	private const DESTRUCTIVE_PATTERNS = array(
		'-delete',
		'-destroy',
		'-remove',
		'-cancel',
		'-refund',
		'-trash',
		'-purge',
		'-reset',
		'-drop',
	);

	/**
	 * Classifications by ability name: [risk, source].
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private array $cache = array();

	/**
	 * The risk class of an ability.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	public function classify( \WP_Ability $ability ): string {
		return $this->resolve( $ability )[0];
	}

	/**
	 * Whether the class came from a guess rather than a declaration.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	public function is_inferred( \WP_Ability $ability ): bool {
		return in_array( $this->resolve( $ability )[1], array( 'heuristic', 'fallback' ), true );
	}

	/**
	 * The grant level a risk class needs.
	 *
	 * @param string $risk One of the RISK_* constants.
	 */
	public static function required_level( string $risk ): string {
		return self::RISK_READ === $risk ? self::LEVEL_READ : self::LEVEL_WRITE;
	}

	/**
	 * Grant levels.
	 *
	 * @return array<int, string>
	 */
	public static function levels(): array {
		return array( self::LEVEL_NONE, self::LEVEL_READ, self::LEVEL_WRITE );
	}

	/**
	 * Classification and its source, cached per ability.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array{0: string, 1: string}
	 */
	private function resolve( \WP_Ability $ability ): array {
		$name = $ability->get_name();

		if ( isset( $this->cache[ $name ] ) ) {
			return $this->cache[ $name ];
		}

		list( $risk, $source ) = $this->determine( $ability, $name );

		/**
		 * Filters the risk class the Brain documents for an ability.
		 *
		 * AI Agent OS hooks its governance classifier here, admin overrides
		 * included. A value that is not a known class is documented as write.
		 *
		 * @param string      $risk    One of read|write|destructive.
		 * @param \WP_Ability $ability The ability.
		 * @param string      $source  declared, annotation, heuristic or fallback.
		 */
		$filtered = apply_filters( 'zorem_agent_brain_ability_risk', $risk, $ability, $source );

		if ( $filtered !== $risk ) {
			$risk   = is_string( $filtered ) ? $filtered : '';
			$source = 'governance';
		}

		if ( ! in_array( $risk, array( self::RISK_READ, self::RISK_WRITE, self::RISK_DESTRUCTIVE ), true ) ) {
			$risk   = self::RISK_WRITE;
			$source = 'fallback';
		}

		$this->cache[ $name ] = array( $risk, $source );

		return $this->cache[ $name ];
	}

	/**
	 * The classification ladder, most authoritative first.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param string      $name    Ability name.
	 * @return array{0: string, 1: string}
	 */
	private function determine( \WP_Ability $ability, string $name ): array {
		$meta = $ability->get_meta();

		if ( isset( $meta['zorem']['risk'] ) && is_string( $meta['zorem']['risk'] ) ) {
			return array( $meta['zorem']['risk'], 'declared' );
		}

		$parts  = explode( '/', $name, 2 );
		$suffix = '-' . ( $parts[1] ?? $name );

		// Irreversible-by-name comes before the annotations: destructiveHint cannot say "irreversible".
		foreach ( self::DESTRUCTIVE_PATTERNS as $pattern ) {
			if ( false !== strpos( $suffix, $pattern ) ) {
				return array( self::RISK_DESTRUCTIVE, 'heuristic' );
			}
		}

		if ( ! empty( $meta['annotations']['readonly'] ) ) {
			return array( self::RISK_READ, 'annotation' );
		}

		// destructiveHint means "not merely additive": a floor of write, never destructive.
		if ( ! empty( $meta['annotations']['destructive'] ) ) {
			return array( self::RISK_WRITE, 'annotation' );
		}

		foreach ( self::READ_PATTERNS as $pattern ) {
			if ( false !== strpos( $suffix, $pattern ) ) {
				return array( self::RISK_READ, 'heuristic' );
			}
		}

		return array( self::RISK_WRITE, 'fallback' );
	}
}
