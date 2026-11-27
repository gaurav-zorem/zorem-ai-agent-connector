<?php
/**
 * Attributes registered artefacts to the plugins that registered them.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Extraction;

defined( 'ABSPATH' ) || exit;

/**
 * Works out which plugin registered what, and how confident we are.
 *
 * WordPress records that a shortcode, post type or REST route exists. It does
 * not record who registered it. Two passes are therefore run and reconciled:
 *
 *   Pass A, runtime: walk the live registries and resolve each callback to the
 *   file that defined it via reflection, then that file to a plugin. Precise,
 *   but blind to anything registered without a callback.
 *
 *   Pass B, static: token-scan the plugin's own files for the calls it makes.
 *   Sees post types and fired hooks that pass A cannot, but only proves the
 *   code contains the call, not that it ran.
 *
 * There is also a third, stronger source that is not a pass at all. When an
 * ability is registered, `AbilityOriginRecorder` records the file that
 * registered it, at the moment it happens. That is direct observation, not
 * inference — it does not reflect a callback, and it cannot be confused by a
 * wrapper. It therefore outranks corroboration between the two passes.
 *
 * Confidence is recorded rather than resolved away, and the *evidence* behind
 * each verdict is recorded next to it:
 *
 *   confident / recorded      — observed at registration (strongest)
 *   confident / corroborated  — both passes agree
 *   runtime_only / reflected  — live and attributed by reflection, not found in
 *                               the plugin's source; carries the §0.4.1 caveat
 *   declared_only / declared  — in the source, no runtime trace (conditional
 *                               registration, or a path that did not run)
 *   unresolved                — exists at runtime, attributable to no plugin
 *
 * The distinction matters because "confident" is reached two different ways and
 * only one of them is inference. A reader deciding how much to trust an entry
 * should be able to see which.
 *
 * The last row matters most. A callback defined in core, an mu-plugin or a
 * shared library resolves to a file inside no plugin directory. It is recorded
 * as unresolved and attributed to nobody, because a knowledge base that guesses
 * an owner is worse than one with an honest gap — the same principle as the
 * tool-name collision guard in governance. Where the resolved file *is* identifiable
 * as somewhere other than a plugin, that origin is recorded too: "this is
 * WordPress core" and "we could not tell" are different answers, and pooling
 * them makes the honest gap look larger and vaguer than it is.
 */
final class PluginAttributionResolver {

	public const CONFIDENT     = 'confident';
	public const RUNTIME_ONLY  = 'runtime_only';
	public const DECLARED_ONLY = 'declared_only';
	public const UNRESOLVED    = 'unresolved';

	/**
	 * Runtime-only, and the reflection that produced it looks like a wrapper.
	 *
	 * A cheap suspicion check, not a verdict: if an artefact was attributed by
	 * reflection but the static scan found no corresponding registration call
	 * anywhere in that plugin's own source, the resolved file is more likely
	 * another plugin's wrapper than the registrant. Surfaced so an admin can
	 * see which attributions rest on the weaker signal.
	 */
	public const RUNTIME_SUSPECT = 'runtime_suspect';

	/**
	 * How an attribution was arrived at.
	 *
	 * Separate from confidence because two artefacts can both be `confident`
	 * for materially different reasons: one was watched registering itself, the
	 * other was inferred twice and the inferences matched.
	 */
	public const EVIDENCE_RECORDED     = 'recorded';
	public const EVIDENCE_CORROBORATED = 'corroborated';
	public const EVIDENCE_REFLECTED    = 'reflected';
	public const EVIDENCE_DECLARED     = 'declared';

	/**
	 * Where an unattributable artefact actually came from.
	 *
	 * `unknown` is the only one that means "we could not determine". The others
	 * are answers.
	 */
	public const ORIGIN_CORE      = 'core';
	public const ORIGIN_MU_PLUGIN = 'mu-plugin';
	public const ORIGIN_THEME     = 'theme';
	public const ORIGIN_DROPIN    = 'dropin';
	public const ORIGIN_UNKNOWN   = 'unknown';

	/**
	 * File and plugin resolution.
	 *
	 * @var PluginLocator
	 */
	private PluginLocator $locator;

	/**
	 * Static source scanning.
	 *
	 * @var TokenScanner
	 */
	private TokenScanner $scanner;

	/**
	 * Registration-time ability origins, when available.
	 *
	 * @var AbilityOriginRecorder|null
	 */
	private ?AbilityOriginRecorder $origins;

	/**
	 * Cached runtime artefacts, by plugin slug.
	 *
	 * @var array<string, array<string, array<int, string>>>|null
	 */
	private ?array $runtime = null;

	/**
	 * Artefacts that resolved to no plugin, by group.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $unresolved = array();

	/**
	 * Where each unattributed artefact came from, group => name => details.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $unresolved_details = array();

	/**
	 * How each runtime attribution was made, slug => group => name => evidence.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $evidence = array();

	/**
	 * Callbacks reflection could not resolve at all.
	 *
	 * @var int
	 */
	private int $reflection_failures = 0;

	/**
	 * Constructor.
	 *
	 * @param PluginLocator              $locator File and plugin resolution.
	 * @param TokenScanner               $scanner Static source scanning.
	 * @param AbilityOriginRecorder|null $origins Registration-time ability origins.
	 */
	public function __construct( PluginLocator $locator, TokenScanner $scanner, ?AbilityOriginRecorder $origins = null ) {
		$this->locator = $locator;
		$this->scanner = $scanner;
		$this->origins = $origins;
	}

	/**
	 * Scan a plugin's own source.
	 *
	 * A pass may span several ticks. `$from_offset` resumes an earlier one and
	 * `$tick_files` bounds how much this tick attempts; the caller stitches the
	 * results together.
	 *
	 * Two different reasons exist for stopping early and they must not be
	 * confused. `truncated` means facts were given up on — files beyond the
	 * corpus budget, or too large to hold — and marks the resulting knowledge
	 * permanently incomplete. `complete` reports whether the file list was
	 * exhausted; false with `truncated` false simply means more ticks are owed.
	 * A caller running a single pass must therefore check `complete`, not just
	 * `truncated`.
	 *
	 * @param string $slug        Plugin slug.
	 * @param int    $max_files   File budget for the whole corpus, across ticks.
	 * @param int    $max_bytes   Byte budget for the whole corpus, across ticks.
	 * @param int    $from_offset Index into the file list to resume at.
	 * @param int    $tick_files  Files this tick may read; 0 for no per-tick cap.
	 * @return array{calls: array<string, array<int, string>>, prose: array<int, string>, files: int, files_found: int, files_remaining: int, next_offset: int, complete: bool, bytes: int, truncated: bool, stop_reason: string|null, skipped_large: int, skipped_memory: int, unparseable: int, memory: array<string, mixed>}
	 */
	public function scan_plugin( string $slug, int $max_files = 1500, int $max_bytes = 12582912, int $from_offset = 0, int $tick_files = 0 ): array {
		$source = $this->locator->source_files( $slug, $max_files, $max_bytes );

		/*
		 * Resume point. The file list is rebuilt each tick from the same
		 * directory walk, which is stable for an unchanged plugin, so an offset
		 * into it identifies the same file next time. A plugin that changed
		 * mid-pass invalidates its checksum and starts over anyway.
		 */
		$all_files       = $source['files'];
		$source['files'] = array_slice( $all_files, max( 0, $from_offset ) );
		$offset          = max( 0, $from_offset );
		$this_tick       = 0;

		$calls       = array();
		$prose       = array();
		$unparseable = 0;
		$scanned     = 0;
		$truncated   = $source['truncated'];
		$stop_reason = $truncated ? 'budget' : null;
		$memory      = new MemoryGuard();

		$skipped_memory = 0;

		foreach ( $source['files'] as $file ) {
			/*
			 * Two memory decisions, and they are different questions.
			 *
			 * First: is there enough left to continue at all? If not, stop and
			 * say so — this is a budget like any other.
			 *
			 * The cursor is NOT advanced here. This file has not been read, and
			 * moving past it would leave a silent gap in the plugin's knowledge
			 * that nothing downstream could detect.
			 */
			if ( $memory->should_stop() ) {
				/*
				 * Also a pause, for the same reason as the tick cap below: these
				 * files are still owed and the next tick gets a fresh arena.
				 * `truncated` is reserved for facts actually given up on —
				 * files past the corpus budget, files too large to hold — so
				 * that an entry marked incomplete really is incomplete.
				 */
				$stop_reason = 'memory';
				break;
			}

			/*
			 * The per-tick cap is a pause, not a truncation. Nothing has been
			 * given up on — the remaining files are still owed — so `truncated`
			 * stays false and only `complete` reports the difference. Marking a
			 * pause as truncation would put a permanent "incomplete" warning on
			 * knowledge that finishes correctly two minutes later.
			 */
			if ( $tick_files > 0 && $this_tick >= $tick_files ) {
				$stop_reason = 'tick_budget';
				break;
			}

			/*
			 * Past the stop check, this file is claimed: the cursor advances
			 * before the work rather than after it. If reading it survives, the
			 * next tick continues correctly; if it kills the process outright,
			 * the next tick moves past it rather than dying on the same file
			 * forever. Fatal repeats are caught separately by the pass retry
			 * cap, since a dead process persists nothing.
			 */
			++$offset;
			++$this_tick;

			/*
			 * Second: will *this* file fit? A token array runs 45-57x its
			 * source, so one large file can exhaust the process even with
			 * healthy headroom. Skipping it costs one file's facts; attempting
			 * it costs the whole request.
			 */
			$size = (int) @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A vanished file is handled as zero.

			if ( $size > 0 && ! $memory->can_scan( $size ) ) {
				++$skipped_memory;
				$truncated = true;

				if ( null === $stop_reason ) {
					$stop_reason = 'memory_per_file';
				}

				continue;
			}

			$scan = $this->scanner->scan_file( $file );
			++$scanned;

			if ( null !== $scan['error'] ) {
				++$unparseable;
				continue;
			}

			foreach ( $scan['calls'] as $group => $names ) {
				foreach ( $names as $name ) {
					if ( ! isset( $calls[ $group ] ) ) {
						$calls[ $group ] = array();
					}

					if ( ! in_array( $name, $calls[ $group ], true ) ) {
						$calls[ $group ][] = $name;
					}
				}
			}

			foreach ( $scan['prose'] as $comment ) {
				$prose[] = $comment;
			}
		}

		return array(
			'calls'           => $calls,
			'prose'           => $prose,
			'files'           => $scanned,
			'files_found'     => count( $all_files ),
			'files_remaining' => max( 0, count( $all_files ) - $offset ),
			'next_offset'     => $offset,
			'complete'        => $offset >= count( $all_files ),
			'bytes'           => $source['bytes'],
			'truncated'       => $truncated,
			'stop_reason'     => $stop_reason,
			'skipped_large'   => $source['skipped_large'],
			'skipped_memory'  => $skipped_memory,
			'unparseable'     => $unparseable,
			'memory'          => $memory->snapshot(),
		);
	}

	/**
	 * Runtime artefacts grouped by plugin slug.
	 *
	 * @return array<string, array<string, array<int, string>>>
	 */
	public function runtime_artefacts(): array {
		if ( null !== $this->runtime ) {
			return $this->runtime;
		}

		$this->runtime    = array();
		$this->unresolved = array();

		$this->collect_abilities();
		$this->collect_shortcodes();
		$this->collect_rest_routes();

		return $this->runtime;
	}

	/**
	 * Artefacts that could not be attributed to any plugin.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function unresolved_artefacts(): array {
		$this->runtime_artefacts();

		return $this->unresolved;
	}

	/**
	 * Reconcile both passes for one plugin.
	 *
	 * @param string                                 $slug Plugin slug.
	 * @param array<string, array<int, string>>|null $scan Static scan results, or null to run one.
	 * @return array<string, array<string, array<string, string>>> Group => name => {confidence, evidence}.
	 */
	public function reconcile( string $slug, ?array $scan = null ): array {
		if ( null === $scan ) {
			$scan = $this->scan_plugin( $slug )['calls'];
		}

		$runtime = $this->runtime_artefacts()[ $slug ] ?? array();
		$groups  = array_unique( array_merge( array_keys( $runtime ), array_keys( $scan ) ) );
		$result  = array();

		foreach ( $groups as $group ) {
			$in_runtime = $runtime[ $group ] ?? array();
			$in_scan    = $scan[ $group ] ?? array();

			foreach ( array_unique( array_merge( $in_runtime, $in_scan ) ) as $name ) {
				$live     = in_array( $name, $in_runtime, true );
				$declared = in_array( $name, $in_scan, true );
				$evidence = (string) ( $this->evidence[ $slug ][ $group ][ $name ] ?? self::EVIDENCE_DECLARED );

				if ( $live && self::EVIDENCE_RECORDED === $evidence ) {
					/*
					 * Watched registering itself. This is direct observation, not
					 * inference, so it does not need a second source to agree with
					 * it — and demanding one would penalise exactly the artefacts
					 * we know most about. WooCommerce's abilities are the case in
					 * point: its registration files sit past the per-plugin file
					 * ceiling, so the static scan can never corroborate them, no
					 * matter how many times it runs.
					 */
					$confidence = self::CONFIDENT;
				} elseif ( $live && $declared ) {
					$confidence = self::CONFIDENT;
					$evidence   = self::EVIDENCE_CORROBORATED;
				} elseif ( $live ) {
					/*
					 * Live, attributed by reflection alone, and undeclared. For
					 * abilities that is expected — the registration is often built
					 * dynamically — but for groups the scanner does cover, a plugin
					 * whose source contains no matching call at all is more likely
					 * to have been resolved through someone else's wrapper than to
					 * have registered it.
					 */
					$confidence = ( 'abilities' !== $group && array() === $in_scan )
						? self::RUNTIME_SUSPECT
						: self::RUNTIME_ONLY;
					$evidence   = self::EVIDENCE_REFLECTED;
				} else {
					$confidence = self::DECLARED_ONLY;
					$evidence   = self::EVIDENCE_DECLARED;
				}

				$result[ $group ][ $name ] = array(
					'confidence' => $confidence,
					'evidence'   => $evidence,
				);
			}
		}

		return $result;
	}

	/**
	 * Unattributed artefacts with their origin, group => name => details.
	 *
	 * @return array<string, array<string, array<string, string>>>
	 */
	public function unresolved_details(): array {
		$this->runtime_artefacts();

		return $this->unresolved_details;
	}

	/**
	 * Unattributed artefact counts by group and origin.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function unresolved_summary(): array {
		$summary = array();

		foreach ( $this->unresolved_details() as $group => $names ) {
			foreach ( $names as $details ) {
				$origin = (string) ( $details['origin'] ?? self::ORIGIN_UNKNOWN );

				$summary[ $group ][ $origin ] = ( $summary[ $group ][ $origin ] ?? 0 ) + 1;
			}
		}

		return $summary;
	}

	/**
	 * Summarise reconciliation confidence counts.
	 *
	 * @param array<string, array<string, array<string, string>>> $reconciled Reconciliation result.
	 * @return array<string, int>
	 */
	public static function summarise( array $reconciled ): array {
		$counts = array(
			self::CONFIDENT       => 0,
			self::RUNTIME_ONLY    => 0,
			self::RUNTIME_SUSPECT => 0,
			self::DECLARED_ONLY   => 0,
		);

		foreach ( $reconciled as $names ) {
			foreach ( $names as $verdict ) {
				$confidence = is_array( $verdict ) ? (string) ( $verdict['confidence'] ?? '' ) : (string) $verdict;

				if ( isset( $counts[ $confidence ] ) ) {
					++$counts[ $confidence ];
				}
			}
		}

		return $counts;
	}

	/**
	 * Summarise which evidence produced each verdict.
	 *
	 * @param array<string, array<string, array<string, string>>> $reconciled Reconciliation result.
	 * @return array<string, int>
	 */
	public static function summarise_evidence( array $reconciled ): array {
		$counts = array(
			self::EVIDENCE_RECORDED     => 0,
			self::EVIDENCE_CORROBORATED => 0,
			self::EVIDENCE_REFLECTED    => 0,
			self::EVIDENCE_DECLARED     => 0,
		);

		foreach ( $reconciled as $names ) {
			foreach ( $names as $verdict ) {
				$evidence = is_array( $verdict ) ? (string) ( $verdict['evidence'] ?? '' ) : '';

				if ( isset( $counts[ $evidence ] ) ) {
					++$counts[ $evidence ];
				}
			}
		}

		return $counts;
	}

	/**
	 * Attribute abilities via their execute callbacks.
	 */
	private function collect_abilities(): void {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}

		foreach ( wp_get_abilities() as $ability ) {
			if ( ! $ability instanceof \WP_Ability ) {
				continue;
			}

			$name = $ability->get_name();

			list( $slug, $file, $evidence ) = $this->attribute_ability( $ability );

			$this->record( $slug, 'abilities', $name, $file, $evidence );
		}
	}

	/**
	 * Attribute shortcodes via their handlers.
	 */
	private function collect_shortcodes(): void {
		global $shortcode_tags;

		if ( ! is_array( $shortcode_tags ) ) {
			return;
		}

		foreach ( $shortcode_tags as $tag => $callback ) {
			$this->record(
				$this->attribute_callback( $callback ),
				'shortcodes',
				(string) $tag,
				$this->callback_file( $callback )
			);
		}
	}

	/**
	 * Attribute REST routes via their callbacks.
	 */
	private function collect_rest_routes(): void {
		if ( ! function_exists( 'rest_get_server' ) || ! did_action( 'rest_api_init' ) ) {
			return;
		}

		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			$slug = null;
			$file = null;

			foreach ( (array) $handlers as $handler ) {
				if ( empty( $handler['callback'] ) ) {
					continue;
				}

				$slug = $this->attribute_callback( $handler['callback'] );

				// Keep the first file we could resolve, so an unattributed
				// route can still say where its handler lives.
				$file = $file ?? $this->callback_file( $handler['callback'] );

				if ( null !== $slug ) {
					break;
				}
			}

			$this->record( $slug, 'rest', (string) $route, $file );
		}
	}

	/**
	 * Resolve an ability to a plugin.
	 *
	 * The ability's namespace is not evidence of ownership — anyone can
	 * register `woocommerce/…` — so the execute callback is reflected instead.
	 *
	 * @param \WP_Ability $ability The ability.
	 * @return array{0: string|null, 1: string|null, 2: string} Slug, file, evidence.
	 */
	private function attribute_ability( \WP_Ability $ability ): array {
		/*
		 * The recorded origin is the only trustworthy signal. Reflecting the
		 * callbacks would name governance's gate on every ability, because the
		 * gate wraps them all — see AbilityOriginRecorder.
		 */
		if ( null !== $this->origins ) {
			$file = $this->origins->origin_of( $ability->get_name() );

			if ( null !== $file ) {
				return array( $this->locator->plugin_for_file( $file ), $file, self::EVIDENCE_RECORDED );
			}
		}

		/*
		 * Fallback for abilities registered before the recorder was hooked.
		 * Its answer is treated as suspect: see reject_own_wrapper() for the
		 * one case where it must be discarded outright.
		 */
		$seen_file = null;

		try {
			$reflection = new \ReflectionObject( $ability );

			foreach ( array( 'execute_callback', 'permission_callback' ) as $property ) {
				if ( ! $reflection->hasProperty( $property ) ) {
					continue;
				}

				$prop = $reflection->getProperty( $property );
				$prop->setAccessible( true );

				$callback  = $prop->getValue( $ability );
				$seen_file = $seen_file ?? $this->callback_file( $callback );

				$slug = $this->attribute_callback( $callback );

				if ( null !== $slug ) {
					return array( $slug, $seen_file, self::EVIDENCE_REFLECTED );
				}
			}
		} catch ( \ReflectionException $e ) {
			++$this->reflection_failures;

			return array( null, null, self::EVIDENCE_REFLECTED );
		}

		return array( null, $seen_file, self::EVIDENCE_REFLECTED );
	}

	/**
	 * Resolve a callable to the plugin that defined it.
	 *
	 * @param mixed $callback Any callable.
	 */
	public function attribute_callback( $callback ): ?string {
		$file = $this->callback_file( $callback );

		if ( null === $file || $this->is_own_wrapper( $file ) ) {
			return null;
		}

		return $this->locator->plugin_for_file( $file );
	}

	/**
	 * Whether a resolved file is one of our own wrappers rather than a registrant.
	 *
	 * An ability with no recorded origin whose callback resolves into
	 * AbilityGate is telling us only that we wrapped it — which is true of
	 * every ability on the site and identifies nobody. Returning our own slug
	 * there would file another plugin's knowledge under ours, so the answer is
	 * discarded and the artefact stays unresolved.
	 *
	 * @param string $file Resolved file path.
	 */
	private function is_own_wrapper( string $file ): bool {
		return AbilityOriginRecorder::is_own_frame( $file );
	}

	/**
	 * The file a callable was defined in.
	 *
	 * @param mixed $callback Any callable.
	 */
	private function callback_file( $callback ): ?string {
		try {
			if ( $callback instanceof \Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$reflection = new \ReflectionFunction( $callback );

				return $this->file_name( $reflection );
			}

			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$reflection = new \ReflectionMethod( $callback[0], (string) $callback[1] );

				return $this->file_name( $reflection );
			}

			if ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$reflection = new \ReflectionMethod( $callback, '__invoke' );

				return $this->file_name( $reflection );
			}

			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $class, $method ) = explode( '::', $callback, 2 );

				$reflection = new \ReflectionMethod( $class, $method );

				return $this->file_name( $reflection );
			}
		} catch ( \ReflectionException $e ) {
			++$this->reflection_failures;

			return null;
		} catch ( \Throwable $e ) {
			/*
			 * Deliberately broad: the callables here come from other people's
			 * plugins and can be anything. But a swallowed failure that is never
			 * counted is indistinguishable from a callback that legitimately has
			 * no file — which is exactly how a wrong type hint in this class hid
			 * for two milestones while every reflection silently returned null.
			 * Counting them makes the difference visible.
			 */
			++$this->reflection_failures;

			return null;
		}

		return null;
	}

	/**
	 * How many callbacks could not be reflected during this pass.
	 *
	 * Reported separately from unattributed artefacts, because "reflection threw"
	 * and "the file belongs to no plugin" are different findings.
	 */
	public function reflection_failures(): int {
		return $this->reflection_failures;
	}

	/**
	 * A reflection's file name, or null for internal or evald code.
	 *
	 * @param \ReflectionFunctionAbstract $reflection Reflected callable.
	 */
	private function file_name( \ReflectionFunctionAbstract $reflection ): ?string {
		$file = $reflection->getFileName();

		return is_string( $file ) && '' !== $file ? $file : null;
	}

	/**
	 * Record an artefact against a plugin, or as unresolved.
	 *
	 * @param string|null $slug     Plugin slug, or null when unattributable.
	 * @param string      $group    Fact group.
	 * @param string      $name     Artefact name.
	 * @param string|null $file     File the artefact resolved to, when known.
	 * @param string      $evidence How the attribution was made.
	 */
	private function record( ?string $slug, string $group, string $name, ?string $file = null, string $evidence = self::EVIDENCE_REFLECTED ): void {
		if ( null === $slug ) {
			if ( ! isset( $this->unresolved[ $group ] ) ) {
				$this->unresolved[ $group ] = array();
			}

			if ( ! in_array( $name, $this->unresolved[ $group ], true ) ) {
				$this->unresolved[ $group ][] = $name;
			}

			$this->unresolved_details[ $group ][ $name ] = array(
				'origin' => self::classify_origin( $file ),
				'file'   => (string) $file,
			);

			return;
		}

		if ( ! isset( $this->runtime[ $slug ][ $group ] ) ) {
			$this->runtime[ $slug ][ $group ] = array();
		}

		if ( ! in_array( $name, $this->runtime[ $slug ][ $group ], true ) ) {
			$this->runtime[ $slug ][ $group ][] = $name;
		}

		$this->evidence[ $slug ][ $group ][ $name ] = $evidence;
	}

	/**
	 * Where a file that belongs to no plugin actually lives.
	 *
	 * "Registered by WordPress itself" and "we could not work out who registered
	 * this" are different findings, and an admin screen that shows them as one
	 * number makes the honest gap look both larger and more alarming than it is.
	 * Only `unknown` means we could not determine.
	 *
	 * @param string|null $file Absolute path, or null when reflection found none.
	 */
	public static function classify_origin( ?string $file ): string {
		if ( null === $file || '' === $file ) {
			/*
			 * No file at all: an internal PHP function, an eval'd closure, or a
			 * callback reflection refused. Genuinely unknown.
			 */
			return self::ORIGIN_UNKNOWN;
		}

		$path = str_replace( '\\', '/', $file );

		$in = static function ( string $dir ) use ( $path ): bool {
			$dir = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/';

			return '' !== trim( $dir, '/' ) && 0 === strpos( $path, $dir );
		};

		if ( defined( 'WPMU_PLUGIN_DIR' ) && $in( (string) constant( 'WPMU_PLUGIN_DIR' ) ) ) {
			return self::ORIGIN_MU_PLUGIN;
		}

		if ( function_exists( 'get_theme_root' ) && $in( (string) get_theme_root() ) ) {
			return self::ORIGIN_THEME;
		}

		if ( $in( ABSPATH . WPINC ) || $in( ABSPATH . 'wp-admin' ) ) {
			return self::ORIGIN_CORE;
		}

		/*
		 * Inside the plugins directory yet attributable to no plugin. This is
		 * the genuinely unknown case — a loose file, a deleted plugin still
		 * hooked, or one of our own wrappers whose answer we deliberately
		 * refused. Calling it a drop-in would be a tidier answer than the
		 * evidence supports.
		 */
		if ( $in( PluginLocator::plugins_dir() ) ) {
			return self::ORIGIN_UNKNOWN;
		}

		/*
		 * Checked after the plugin and theme directories, since both normally
		 * live inside wp-content. What is left is a drop-in or a loose file an
		 * administrator placed there.
		 */
		if ( defined( 'WP_CONTENT_DIR' ) && $in( (string) constant( 'WP_CONTENT_DIR' ) ) ) {
			return self::ORIGIN_DROPIN;
		}

		if ( 0 === strpos( $path, rtrim( str_replace( '\\', '/', ABSPATH ), '/' ) . '/' ) ) {
			return self::ORIGIN_CORE;
		}

		return self::ORIGIN_UNKNOWN;
	}

	/**
	 * Clear caches. Used by tests.
	 */
	public function flush(): void {
		$this->runtime             = null;
		$this->unresolved          = array();
		$this->unresolved_details  = array();
		$this->evidence            = array();
		$this->reflection_failures = 0;
		$this->locator->flush();
	}
}
