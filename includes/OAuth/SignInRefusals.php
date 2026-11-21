<?php
/**
 * Refused sign-ins, on the Connect page.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\OAuth;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Mcp\LastRefusal;

/**
 * Remembers why an app's sign-in was turned away (a refused registration or
 * token request), so the Connect page can say so.
 *
 * Without it a refused sign-in left no trace: the app said "problem
 * connecting" and the administrator had nothing to go on (found while
 * connecting Grok). This plugin keeps no Activity Log, so the reason goes
 * to the same "last refused connection" note a bad token does; LastRefusal
 * rate-limits it, since anyone can post a bad registration.
 */
final class SignInRefusals {

	/**
	 * The last refused connection.
	 *
	 * @var LastRefusal
	 */
	private LastRefusal $last;

	/**
	 * Constructor.
	 *
	 * @param LastRefusal $last The last refused connection.
	 */
	public function __construct( LastRefusal $last ) {
		$this->last = $last;
	}

	/**
	 * Record a refused sign-in.
	 *
	 * The app, callback and detail are accepted for the same calls as AI Agent
	 * OS PRO, which logs them; here only the reason is kept.
	 *
	 * @param string $reason   A Decision::OAUTH_* code.
	 * @param string $app      The name the app gave, or its client id.
	 * @param string $callback The callback address it asked for, if any.
	 * @param string $detail   What exactly was wrong, in a sentence.
	 */
	public function record( string $reason, string $app = '', string $callback = '', string $detail = '' ): void {
		unset( $app, $callback, $detail );

		$this->last->record( $reason );
	}
}
