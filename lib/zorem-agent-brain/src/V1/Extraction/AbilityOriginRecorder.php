<?php
/**
 * Records which file registered each ability.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Captures an ability's origin at registration time, because afterwards it is
 * unrecoverable.
 *
 * Reflection on an ability's callbacks is the obvious way to attribute it, and
 * it does not work once governance is installed — AI Agent OS's ability gate
 * wraps *every* ability's permission and execute callbacks in its own closures,
 * so by the time extraction runs every ability reflects to Core's file. That is
 * not a bug in the gate; wrapping everything is precisely how governance covers
 * doors it does not own. But it means the callback carries the gate's identity,
 * not the registrant's. Hence this recorder runs at priority 1 on
 * `wp_register_ability_args`, and the gate must filter later.
 *
 * Nor is the ability's namespace usable: anything can register
 * `woocommerce/…`, and believing the prefix would let one plugin write
 * another's knowledge — the same trust mistake the Provenance split exists to
 * avoid.
 *
 * So the origin is recorded when it is still visible. `wp_register_ability_args`
 * fires inside the registering plugin's own call stack, so a backtrace at that
 * moment names the file. Hooked at priority 1, ahead of the gate's 999, and it
 * only reads — it never alters the arguments it is passed.
 */
final class AbilityOriginRecorder {

	/**
	 * Ability name => absolute file that registered it.
	 *
	 * @var array<string, string>
	 */
	private array $origins = array();

	/**
	 * Path fragments that are never the registrant.
	 *
	 * Core's abilities API is the machinery doing the registering, and our own
	 * extraction and gate code sits in the stack on every call.
	 *
	 * @var array<int, string>
	 */
	private const IGNORED_FRAGMENTS = array(
		// The registry classes.
		'/wp-includes/abilities-api/',
		// wp_register_ability() itself — one character from the line above, and
		// the frame that actually appears in the stack. Missing it attributed
		// every ability on the site to WordPress core.
		'/wp-includes/abilities-api.php',
		'/wp-includes/abilities.php',
		'/wp-includes/class-wp-hook.php',
		'/wp-includes/plugin.php',
	);

	/**
	 * Our own files that sit in the stack on every registration.
	 *
	 * Only these, not whole plugin directories. Skipping everything of ours
	 * would hide each plugin's own ability registrars as well, leaving it
	 * unable to attribute its own abilities to itself. The recorder is matched
	 * by its path inside the kit, so it is skipped from whichever host's
	 * bundled copy loaded; the gate is AI Agent OS's.
	 *
	 * @var array<int, string>
	 */
	private const OWN_FRAMES = array(
		'/src/V1/Extraction/AbilityOriginRecorder.php',
		'ai-agent-os/includes/Governance/AbilityGate.php',
	);

	/**
	 * Hook the recorder.
	 *
	 * Must run before any ability registers, so it is wired during boot rather
	 * than lazily on first use.
	 */
	public function register(): void {
		add_filter( 'wp_register_ability_args', array( $this, 'record' ), 1, 2 );
	}

	/**
	 * Record where an ability is being registered from.
	 *
	 * @param array<string, mixed> $args Registration arguments, returned untouched.
	 * @param string               $name Ability name.
	 * @return array<string, mixed>
	 */
	public function record( array $args, string $name ): array {
		/*
		 * The latest attempt wins, not the first. WordPress fires this filter
		 * before it validates the arguments, so a registration that then fails
		 * (an unknown category, a bad class) is recorded too. Keeping the first
		 * let a plugin register a name early with a bad category, fail, and stay
		 * on record as the owner of the real ability registered after it. A
		 * name that registers successfully never reaches this filter again
		 * (the registry refuses duplicates before filtering), so the last
		 * record is the one that succeeded.
		 */
		$file = $this->calling_file();

		if ( null !== $file ) {
			$this->origins[ $name ] = $file;
		} else {
			unset( $this->origins[ $name ] );
		}

		return $args;
	}

	/**
	 * The file that registered the ability currently being processed.
	 */
	private function calling_file(): ?string {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- The call stack is the only place this information exists.
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 );

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$file = str_replace( '\\', '/', (string) $frame['file'] );

			if ( self::is_own_frame( $file ) ) {
				continue;
			}

			foreach ( self::IGNORED_FRAGMENTS as $fragment ) {
				if ( false !== strpos( $file, $fragment ) ) {
					continue 2;
				}
			}

			return $file;
		}

		return null;
	}

	/**
	 * Whether a file is one of ours that always appears in a registration stack.
	 *
	 * @param string $file Normalised absolute path.
	 */
	public static function is_own_frame( string $file ): bool {
		$normalized = str_replace( '\\', '/', $file );

		foreach ( self::OWN_FRAMES as $own ) {
			if ( false !== strpos( $normalized, $own ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The recorded origin for an ability, if it was seen registering.
	 *
	 * @param string $name Ability name.
	 */
	public function origin_of( string $name ): ?string {
		return $this->origins[ $name ] ?? null;
	}

	/**
	 * Every recorded origin.
	 *
	 * @return array<string, string>
	 */
	public function all(): array {
		return $this->origins;
	}

	/**
	 * Forget everything. Used by tests.
	 */
	public function flush(): void {
		$this->origins = array();
	}
}
