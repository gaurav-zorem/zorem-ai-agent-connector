<?php
/**
 * Ability risk classification.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

defined( 'ABSPATH' ) || exit;

/**
 * Works out how dangerous an ability is.
 *
 * Third-party abilities carry no risk metadata of their own, so this has to
 * infer. The final fallback is deliberately 'write', never 'read': an ability
 * we cannot classify must not be treated as cheap. Under-protection is a
 * security bug; over-protection is an admin toggling a switch.
 *
 * The ability's name sets a floor first: a destructive word (delete, refund,
 * cancel, …) makes it destructive, a write word (update, send, replace, …)
 * makes it write. What the author declares — meta.zorem.risk or the readonly
 * annotation — may raise the risk above that floor and never lower it below.
 * Nothing lowers a risk (hand-set levels were removed in 1.0.1). Read is reached only
 * through a declaration or annotation on a name with no write word, or a name
 * that reads as a read (`get-tracking`, `orders-query`).
 *
 * One mapping is deliberately lossy. MCP's destructiveHint annotation means
 * "not merely additive" — WooCommerce marks an ordinary order status change
 * with it — while our destructive tier means "irreversible, double opt-in
 * required". Treating the annotation as our tier double-gates every ordinary
 * update and the tier stops meaning anything, so annotations.destructive is
 * taken as a floor of 'write'.
 */
final class RiskClassifier {

	public const RISK_READ        = 'read';
	public const RISK_WRITE       = 'write';
	public const RISK_DESTRUCTIVE = 'destructive';


	/**
	 * Order statuses whose setting is irreversible in effect: stock returned,
	 * money refunded, the customer told. Setting one needs the destructive gate.
	 */
	public const DESTRUCTIVE_STATUSES = array( 'cancelled', 'refunded', 'failed', 'trash' );

	/**
	 * Words implying a read-only ability, matched as whole words of the name.
	 */
	private const READ_WORDS = array(
		'query',
		'get',
		'list',
		'read',
		'search',
		'find',
		'info',
		'status',
		'report',
		'preview',
		'discover',
		'view',
		'fetch',
		'lookup',
		'count',
	);

	/**
	 * Read words that count only as the first word.
	 *
	 * At the end of a name these are the thing acted on (`update-order-status`,
	 * `send-report`), not a sign the ability only reads.
	 */
	private const LEADING_ONLY_READ_WORDS = array( 'status', 'report', 'info', 'preview', 'count' );

	/**
	 * Words implying an ability that changes something.
	 *
	 * Any one anywhere in the name sets a floor of write.
	 */
	private const WRITE_WORDS = array(
		'add',
		'create',
		'insert',
		'update',
		'edit',
		'set',
		'change',
		'save',
		'write',
		'send',
		'notify',
		'publish',
		'import',
		'upload',
		'replace',
		'move',
		'sync',
		'assign',
		'apply',
		'approve',
		'reject',
		'mark',
		'restore',
		'execute',
		'trigger',
		'install',
		'activate',
		'enable',
		'disable',
		'generate',
		'process',
		'charge',
		'pay',
		'merge',
		'rename',
		'toggle',
		'upsert',
		'patch',
		'complete',
		'refresh',
		'regenerate',
	);

	/**
	 * Words implying an irreversible ability.
	 */
	private const DESTRUCTIVE_WORDS = array(
		'delete',
		'destroy',
		'remove',
		'cancel',
		'refund',
		'trash',
		'purge',
		'reset',
		'drop',
		'erase',
		'wipe',
		'void',
		'revoke',
		'clear',
		'flush',
		'deactivate',
		'uninstall',
		'anonymize',
		'anonymise',
		'overwrite',
		'empty',
		'bulk',
		'truncate',
		'unpublish',
	);

	/**
	 * Risk rank, lowest first.
	 */
	private const RANK = array(
		self::RISK_READ        => 0,
		self::RISK_WRITE       => 1,
		self::RISK_DESTRUCTIVE => 2,
	);

	/**
	 * Per-request classification cache, ability name => risk.
	 *
	 * @var array<string, string>
	 */
	private array $cache = array();

	/**
	 * Where each cached classification came from, ability name => source.
	 *
	 * One of: declared, annotation, heuristic, filter (raised only), fallback.
	 *
	 * @var array<string, string>
	 */
	private array $sources = array();

	/**
	 * Classify an ability.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	public function classify( \WP_Ability $ability ): string {
		$name = $ability->get_name();

		if ( isset( $this->cache[ $name ] ) ) {
			return $this->cache[ $name ];
		}

		list( $risk, $source ) = $this->determine( $ability, $name );

		/**
		 * Filters the risk classification of an ability. Raise-only.
		 *
		 * The risk decides which grant level and which gates a call needs, so it
		 * is an authorisation input: a filter that could lower `destructive` to
		 * `read` would let a read grant delete things. A filter may raise the
		 * classification; a lower value is ignored. Nothing lowers a risk:
		 * the hand-set levels of the Settings screen were removed in 1.0.1.
		 *
		 * @param string      $risk    One of read|write|destructive.
		 * @param \WP_Ability $ability The ability being classified.
		 */
		$filtered = (string) apply_filters( 'ai_agent_os_ability_risk', $risk, $ability );

		if ( isset( self::RANK[ $filtered ], self::RANK[ $risk ] ) && self::RANK[ $filtered ] > self::RANK[ $risk ] ) {
			$risk   = $filtered;
			$source = 'filter';
		}

		if ( ! isset( self::RANK[ $risk ] ) ) {
			$risk   = self::RISK_WRITE;
			$source = 'fallback';
		}

		$this->cache[ $name ]   = $risk;
		$this->sources[ $name ] = $source;

		return $risk;
	}

	/**
	 * Whether a classification came from a heuristic rather than a declaration.
	 *
	 * Surfaced in the admin so a guess is visibly a guess. This includes an
	 * ability escalated to destructive purely on its name: that is our
	 * inference, not the author's word.
	 *
	 * @param \WP_Ability $ability The ability.
	 */
	public function is_inferred( \WP_Ability $ability ): bool {
		$name = $ability->get_name();

		if ( ! isset( $this->sources[ $name ] ) ) {
			$this->classify( $ability );
		}

		return in_array( $this->sources[ $name ] ?? 'fallback', array( 'heuristic', 'fallback' ), true );
	}

	/**
	 * Run the classification ladder.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @param string      $name    Ability name.
	 * @return array{0: string, 1: string} Risk and its source.
	 */
	private function determine( \WP_Ability $ability, string $name ): array {
		$meta  = $ability->get_meta();
		$words = self::words( $name );
		$floor = self::name_floor( $words );

		// 2. Our own risk vocabulary, declared by the ability's author: it may raise the name's floor, never lower it.
		if ( isset( $meta['zorem']['risk'] ) && is_string( $meta['zorem']['risk'] ) && isset( self::RANK[ $meta['zorem']['risk'] ] ) ) {
			$declared = $meta['zorem']['risk'];

			return null !== $floor && self::RANK[ $floor ] > self::RANK[ $declared ]
				? array( $floor, 'heuristic' )
				: array( $declared, 'declared' );
		}

		/*
		 * 3. The name's floor. Checked before the annotations because MCP's
		 * destructiveHint cannot distinguish "modifies" from "irreversible",
		 * and a readonly annotation on a name that says it changes something
		 * is a mistake at best. Over-protection here costs an agent a
		 * switch; under-protection is a deleted order.
		 */
		if ( null !== $floor ) {
			return array( $floor, 'heuristic' );
		}

		// 4. An author-declared readonly annotation, on a name with no write word.
		if ( ! empty( $meta['annotations']['readonly'] ) ) {
			return array( self::RISK_READ, 'annotation' );
		}

		/*
		 * 5. MCP destructiveHint means "not merely additive", nothing more —
		 * WooCommerce sets it on an ordinary status update. It is a floor of
		 * write, never our destructive tier. See the class docblock.
		 */
		if ( ! empty( $meta['annotations']['destructive'] ) ) {
			return array( self::RISK_WRITE, 'annotation' );
		}

		// 6. Read-shaped names.
		if ( self::reads_by_name( $words ) ) {
			return array( self::RISK_READ, 'heuristic' );
		}

		// 7. Fail safe, not fail cheap.
		return array( self::RISK_WRITE, 'fallback' );
	}

	/**
	 * The words of an ability name after the namespace.
	 *
	 * @param string $name Full ability name.
	 * @return array<int, string>
	 */
	private static function words( string $name ): array {
		$parts = explode( '/', strtolower( $name ), 2 );
		$words = preg_split( '/[^a-z0-9]+/', $parts[1] ?? $parts[0] );

		return array_values( array_filter( false === $words ? array() : $words, 'strlen' ) );
	}

	/**
	 * The lowest risk the name's own words allow, or null when they say nothing.
	 *
	 * @param array<int, string> $words Name words.
	 */
	private static function name_floor( array $words ): ?string {
		if ( array() !== array_intersect( $words, self::DESTRUCTIVE_WORDS ) ) {
			return self::RISK_DESTRUCTIVE;
		}

		if ( array() !== array_intersect( $words, self::WRITE_WORDS ) ) {
			return self::RISK_WRITE;
		}

		return null;
	}

	/**
	 * Whether the name reads as read-only: its first word is a read word, or
	 * its last word is a read verb (`orders-query`).
	 *
	 * @param array<int, string> $words Name words.
	 */
	private static function reads_by_name( array $words ): bool {
		if ( array() === $words ) {
			return false;
		}

		if ( in_array( $words[0], self::READ_WORDS, true ) ) {
			return true;
		}

		$last = $words[ count( $words ) - 1 ];

		return in_array( $last, self::READ_WORDS, true ) && ! in_array( $last, self::LEADING_ONLY_READ_WORDS, true );
	}

	/**
	 * Forget what this instance read and worked out, so the next call reads the option again.
	 */
	public function flush(): void {
		$this->cache   = array();
		$this->sources = array();
	}

	/**
	 * Whether this call's input makes a write ability irreversible in effect.
	 *
	 * Risk is rated per ability, but an order status update that sets
	 * `cancelled` or `refunded` does what the cancel and refund abilities do,
	 * and must pass the same destructive gate.
	 *
	 * @param mixed $input The call's input.
	 */
	public static function input_is_destructive( $input ): bool {
		if ( ! is_array( $input ) || ! isset( $input['status'] ) || ! is_string( $input['status'] ) ) {
			return false;
		}

		$status = strtolower( trim( $input['status'] ) );

		if ( 0 === strpos( $status, 'wc-' ) ) {
			$status = substr( $status, 3 );
		}

		return in_array( $status, self::DESTRUCTIVE_STATUSES, true );
	}

	/**
	 * The grant level an ability of this risk requires.
	 *
	 * @param string $risk One of the RISK_* constants.
	 */
	public static function required_level( string $risk ): string {
		return self::RISK_READ === $risk
			? PermissionRepository::LEVEL_READ
			: PermissionRepository::LEVEL_WRITE;
	}
}
