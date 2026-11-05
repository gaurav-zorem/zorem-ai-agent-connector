<?php
/**
 * Request-scoped capability elevation.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Governance\CapMaps\CapMapLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an agent's shadow user pass the capability checks an ability performs
 * on itself — and only for as long as that ability is running.
 *
 * The problem this solves: WooCommerce's ability callbacks ask
 * current_user_can( 'edit_shop_orders' ). An agent's shadow user holds 'read'
 * and nothing else, so a granted write ability would fail its own permission
 * check. Persisting those capabilities on the shadow user would fix it and
 * destroy the model — the agent would then hold standing privilege that
 * outlives any single call and is invisible to the permission matrix.
 *
 * So the capabilities are granted for the duration of one callback invocation
 * and withdrawn in a finally block. Outside that window the shadow user is
 * a 'read'-only account again, including during rest_pre_dispatch, during
 * other plugins' hooks on the same request, and between two ability calls in
 * the same MCP session.
 *
 * Two modes, decided per ability:
 *
 *   - Mapped: a curated map (or the ability's own meta.zorem.required_caps)
 *     names the capabilities, and exactly those are lent.
 *   - Automatic: nothing names them, so each capability the ability's code
 *     asks for during the call is lent as it is asked — except the ones in
 *     NEVER_LENT, which would let a call take over the site. Without this,
 *     every ability from a plugin we have not mapped is granted on the
 *     Permissions screen and refused by WordPress at call time.
 *
 * Either way the capabilities are a downstream compatibility shim. They are
 * never the reason an agent is allowed to do something — PermissionResolver
 * has already said yes before this class is used at all. What was lent and
 * what was withheld is reported so the call's activity row records it.
 */
final class CapabilityBridge {

	/**
	 * Capabilities never lent automatically.
	 *
	 * Each of these lets whoever holds it change who can do what on the site,
	 * or run code that is not an ability: users and roles, plugins and themes
	 * and their files, core updates, unfiltered markup, imports, and every
	 * network-level capability. An ability that asks for one is refused, and
	 * says so. A curated map may still name one deliberately — that is a
	 * decision made by reading the ability's code, not inferred at call time.
	 *
	 * Deliberately not filterable (PATTERNS Rule 9): this list is an input to
	 * an authorisation decision.
	 *
	 * @var array<int, string>
	 */
	private const NEVER_LENT = array(
		'do_not_allow',
		'create_users',
		'edit_users',
		'delete_users',
		'remove_users',
		'promote_users',
		'add_users',
		'install_plugins',
		'activate_plugins',
		'update_plugins',
		'delete_plugins',
		'edit_plugins',
		'upload_plugins',
		'resume_plugins',
		'install_themes',
		'switch_themes',
		'update_themes',
		'delete_themes',
		'edit_themes',
		'upload_themes',
		'resume_themes',
		'edit_files',
		'update_core',
		'unfiltered_html',
		'unfiltered_upload',
		'import',
		'export',
		'edit_theme_options',
		'install_languages',
		'update_languages',
		'manage_agent_os',
		'setup_network',
		'upgrade_network',
		'create_sites',
		'delete_sites',
		'delete_site',
		'manage_sites',
		'list_users',
		'upload_files',
		'moderate_comments',
	);

	/**
	 * Fragments of capability names never lent automatically.
	 *
	 * Site settings (`manage_options` and every plugin's own `*_manage_*`
	 * admin capability, `manage_woocommerce`, `manage_privacy_options`),
	 * other people's content (`edit_others_*`, `delete_others_*`, the private
	 * ones), publishing, and personal-data export and erasure. An unmapped
	 * ability that reaches for one of these is reaching past what its
	 * grant on the Permissions screen describes.
	 *
	 * @var array<int, string>
	 */
	private const NEVER_LENT_FRAGMENTS = array(
		'manage',
		'_others_',
		'_private_',
		'personal_data',
	);

	/**
	 * Ability to capability mapping.
	 *
	 * @var CapMapLoader
	 */
	private CapMapLoader $cap_maps;

	/**
	 * The calls in progress, innermost last.
	 *
	 * Each call holds its own mapped capabilities. A call made from inside
	 * another (an ability that runs another ability) gets only what its own
	 * map names, not what the outer call was lent as well.
	 *
	 * @var array<int, array{caps: array<string, bool>, automatic: bool, lent: array<string, bool>, withheld: array<string, bool>}>
	 */
	private array $frames = array();

	/**
	 * What the most recently finished call was lent and refused.
	 *
	 * @var array{automatic: bool, lent: array<int, string>, withheld: array<int, string>}
	 */
	private array $last_report = array(
		'automatic' => false,
		'lent'      => array(),
		'withheld'  => array(),
	);

	/**
	 * Whether the user_has_cap filter is currently attached.
	 *
	 * @var bool
	 */
	private bool $hooked = false;

	/**
	 * Constructor.
	 *
	 * @param CapMapLoader $cap_maps Ability to capability mapping.
	 */
	public function __construct( CapMapLoader $cap_maps ) {
		$this->cap_maps = $cap_maps;
	}

	/**
	 * Run a callback with the capabilities an ability requires.
	 *
	 * @param \WP_Ability $ability  The ability about to be checked or executed.
	 * @param callable    $callback The work to run.
	 * @return mixed Whatever the callback returns.
	 */
	public function with_caps( \WP_Ability $ability, callable $callback ) {
		// Elevation only ever applies to agent traffic. Humans are unaffected.
		if ( ! AgentContext::has() ) {
			return $callback();
		}

		$caps = $this->cap_maps->resolve( $ability );

		$this->push( $caps ?? array(), null === $caps );

		try {
			return $callback();
		} finally {
			$this->pop();
		}
	}

	/**
	 * What the most recently finished call was lent and refused.
	 *
	 * `automatic` is whether the call ran without a map; `lent` lists the
	 * capabilities granted on demand, `withheld` the ones refused because
	 * they are in NEVER_LENT.
	 *
	 * @return array{automatic: bool, lent: array<int, string>, withheld: array<int, string>}
	 */
	public function last_report(): array {
		return $this->last_report;
	}

	/**
	 * Begin a call.
	 *
	 * @param array<int, string> $caps      Mapped capability names.
	 * @param bool               $automatic Whether to lend on demand.
	 */
	private function push( array $caps, bool $automatic ): void {
		$this->frames[] = array(
			'caps'      => array_fill_keys( $caps, true ),
			'automatic' => $automatic,
			'lent'      => array(),
			'withheld'  => array(),
		);

		if ( ! $this->hooked ) {
			add_filter( 'user_has_cap', array( $this, 'grant' ), 10, 4 );
			$this->hooked = true;
		}
	}

	/**
	 * End the innermost call; stop granting once the outermost completes.
	 */
	private function pop(): void {
		$frame = array_pop( $this->frames );

		if ( is_array( $frame ) ) {
			$this->last_report = array(
				'automatic' => $frame['automatic'],
				'lent'      => array_keys( $frame['lent'] ),
				'withheld'  => array_keys( $frame['withheld'] ),
			);
		}

		if ( array() !== $this->frames ) {
			return;
		}

		if ( $this->hooked ) {
			remove_filter( 'user_has_cap', array( $this, 'grant' ), 10 );
			$this->hooked = false;
		}
	}

	/**
	 * Add the capabilities this call may have to the agent's shadow user.
	 *
	 * @param array<string, bool> $allcaps All capabilities the user has.
	 * @param array<int, string>  $caps    Primitive capabilities being checked.
	 * @param array<int, mixed>   $args    Arguments to the capability check.
	 * @param \WP_User|null       $user    The user being checked.
	 * @return array<string, bool>
	 */
	public function grant( array $allcaps, array $caps, array $args, $user = null ): array {
		unset( $args );

		$agent = AgentContext::get();

		if ( null === $agent || array() === $this->frames ) {
			return $allcaps;
		}

		/*
		 * Only ever elevate the shadow user belonging to the agent making this
		 * request. Without this check, a capability test run against some other
		 * user during an agent request would be answered with the agent's
		 * elevated capabilities.
		 */
		$user_id = $user instanceof \WP_User ? (int) $user->ID : 0;

		if ( $user_id < 1 || $user_id !== $agent->get_shadow_user_id() ) {
			return $allcaps;
		}

		$top = count( $this->frames ) - 1;

		foreach ( array_keys( $this->frames[ $top ]['caps'] ) as $cap ) {
			$allcaps[ (string) $cap ] = true;
		}

		if ( ! $this->frames[ $top ]['automatic'] ) {
			return $allcaps;
		}

		foreach ( $caps as $cap ) {
			$cap = (string) $cap;

			if ( ! empty( $allcaps[ $cap ] ) ) {
				continue;
			}

			if ( self::is_never_lent( $cap ) ) {
				$this->frames[ $top ]['withheld'][ $cap ] = true;
				continue;
			}

			$allcaps[ $cap ]                      = true;
			$this->frames[ $top ]['lent'][ $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Whether a capability may never be lent on demand.
	 *
	 * Families of capabilities are covered by fragment (every `manage*`,
	 * `*_others_*`, `publish_*`), so one added by WordPress or another plugin
	 * later is withheld without this list being updated.
	 *
	 * @param string $cap Capability name.
	 */
	public static function is_never_lent( string $cap ): bool {
		if ( in_array( $cap, self::NEVER_LENT, true ) || 0 === strpos( $cap, 'publish_' ) ) {
			return true;
		}

		foreach ( self::NEVER_LENT_FRAGMENTS as $fragment ) {
			if ( false !== strpos( $cap, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The capabilities the innermost call is being granted from its map. Used by tests.
	 *
	 * @return array<int, string>
	 */
	public function active_capabilities(): array {
		$top = end( $this->frames );

		return is_array( $top ) ? array_map( 'strval', array_keys( $top['caps'] ) ) : array();
	}
}
