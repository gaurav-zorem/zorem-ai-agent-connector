<?php
/**
 * The most recent refused connection to the MCP address.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps one fact: when the MCP address last refused a request, and why.
 *
 * This plugin keeps no log of the agent's calls (the Activity Log is part of
 * AI Agent OS PRO), so a connection that failed left no trace on the site at
 * all. A Claude connector that signed in and then failed on its first call
 * showed only "returned an error when connecting", and the site had nothing
 * to say about it. One option holding the latest refusal is enough to tell a
 * stripped Authorization header from an expired token or a replaced address,
 * without keeping anything the agent did or asked.
 *
 * Anyone can be refused, as often as they like, so the option is rewritten at
 * most once a minute for the same reason and never more than once every few
 * seconds whatever the reason.
 */
final class LastRefusal {

	/**
	 * Option name. Not autoloaded: only the admin screens read it.
	 */
	public const OPTION = 'ai_agent_os_last_refusal';

	/**
	 * Seconds before the same reason is written again.
	 */
	private const SAME_REASON_INTERVAL = 60;

	/**
	 * Seconds before any reason is written again.
	 */
	private const ANY_REASON_INTERVAL = 5;

	/**
	 * Record a refusal.
	 *
	 * @param string $reason A Decision reason.
	 */
	public function record( string $reason ): void {
		$now  = time();
		$last = $this->get();

		if ( null !== $last ) {
			$age = $now - (int) strtotime( $last['at'] . ' UTC' );

			if ( $age < self::ANY_REASON_INTERVAL || ( $last['reason'] === $reason && $age < self::SAME_REASON_INTERVAL ) ) {
				return;
			}
		}

		update_option(
			self::OPTION,
			array(
				'reason' => sanitize_key( $reason ),
				'at'     => gmdate( 'Y-m-d H:i:s', $now ),
			),
			false
		);
	}

	/**
	 * The latest refusal, or null when there has been none.
	 *
	 * @return array{reason: string, at: string}|null
	 */
	public function get(): ?array {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) || empty( $stored['reason'] ) || empty( $stored['at'] ) ) {
			return null;
		}

		return array(
			'reason' => (string) $stored['reason'],
			'at'     => (string) $stored['at'],
		);
	}
}
