<?php
/**
 * Who may grant what.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

use AiAgentOs\Governance\CapMaps\CapMapLoader;

defined( 'ABSPATH' ) || exit;

/**
 * A person may give an agent only what they could do themselves.
 *
 * `manage_agent_os` can be handed to a Shop Manager (decision D-7). Without
 * this check that person could grant an agent any ability on the site, and the
 * ability would then run with capabilities the Shop Manager does not hold.
 *
 * The rule: an administrator (`manage_options`) may grant anything. Anyone
 * else may grant an ability only when it names its capabilities (a curated
 * map, or the ability's own `meta.zorem.required_caps`) and they hold every
 * one. An ability with no map needs an administrator, because nobody can say
 * in advance what it will ask for. Taking a grant away is always allowed.
 *
 * With no user (WP-CLI without --user, activation, cron) the check does not
 * apply: whoever runs those already controls the site.
 */
final class GrantAuthority {

	/**
	 * Ability to capability mapping.
	 *
	 * @var CapMapLoader
	 */
	private CapMapLoader $cap_maps;

	/**
	 * Ability registry access.
	 *
	 * @var AbilityInventory
	 */
	private AbilityInventory $inventory;

	/**
	 * Constructor.
	 *
	 * @param CapMapLoader     $cap_maps  Ability to capability mapping.
	 * @param AbilityInventory $inventory Ability registry access.
	 */
	public function __construct( CapMapLoader $cap_maps, AbilityInventory $inventory ) {
		$this->cap_maps  = $cap_maps;
		$this->inventory = $inventory;
	}

	/**
	 * Whether a user may set this grant.
	 *
	 * @param string $ability_name Ability name.
	 * @param string $level        Grant level.
	 * @param int    $user_id      The user setting it; 0 for none.
	 */
	public function may_grant( string $ability_name, string $level, int $user_id ): bool {
		if ( PermissionRepository::LEVEL_NONE === $level || $user_id < 1 || user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$ability = $this->inventory->get( $ability_name );

		if ( ! $ability instanceof \WP_Ability ) {
			return false;
		}

		$caps = $this->cap_maps->resolve( $ability );

		if ( null === $caps ) {
			return false;
		}

		foreach ( $caps as $cap ) {
			if ( ! user_can( $user_id, $cap ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Split grants into the ones a user may set and the ones they may not.
	 *
	 * @param array<string, string> $grants  Ability name => level.
	 * @param int                   $user_id The user setting them.
	 * @return array{0: array<string, string>, 1: array<int, string>} Allowed grants, refused ability names.
	 */
	public function split( array $grants, int $user_id ): array {
		$allowed = array();
		$refused = array();

		foreach ( $grants as $ability_name => $level ) {
			if ( $this->may_grant( (string) $ability_name, (string) $level, $user_id ) ) {
				$allowed[ $ability_name ] = $level;
			} else {
				$refused[] = (string) $ability_name;
			}
		}

		return array( $allowed, $refused );
	}

	/**
	 * Whether a user may change site-wide settings and risk ratings.
	 *
	 * @param int $user_id User.
	 */
	public static function manages_site( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}
}
