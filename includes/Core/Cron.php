<?php
/**
 * Scheduled tasks.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin's WP-Cron events.
 */
final class Cron {

	public const PRUNE_EVENT = 'ai_agent_os_prune_activity';

	/**
	 * Events this plugin no longer schedules. Unhooked on deactivation so a
	 * site upgraded from an earlier release is not left with an orphan.
	 */
	private const RETIRED_EVENTS = array( 'ai_agent_os_rollup_usage' );

	/**
	 * Plugin settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Plugin settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Make sure the schedule exists.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule the events that are missing.
	 *
	 * Checked on every request rather than only at activation, so a site that
	 * lost its cron entries recovers on its own.
	 */
	public function ensure_scheduled(): void {
		foreach ( self::schedules() as $event => $recurrence ) {
			if ( ! wp_next_scheduled( $event ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, $event );
			}
		}
	}

	/**
	 * The scheduled events and how often each runs: the daily clean-up, where
	 * the sign-in store removes expired tokens (OAuthStore).
	 *
	 * @return array<string, string> Event => recurrence.
	 */
	public static function schedules(): array {
		return array(
			self::PRUNE_EVENT => 'daily',
		);
	}

	/**
	 * The scheduled events.
	 *
	 * @return array<int, string>
	 */
	public static function events(): array {
		return array_keys( self::schedules() );
	}

	/**
	 * Remove the scheduled events, retired ones included. Called on deactivation.
	 */
	public static function unschedule(): void {
		foreach ( array_merge( self::events(), self::RETIRED_EVENTS ) as $event ) {
			wp_unschedule_hook( $event );
		}
	}
}
