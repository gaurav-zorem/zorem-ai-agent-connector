<?php
/**
 * The legacy plugin's public API, for integrations written against it.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Defines `zorem_register_skill()`, `zorem_agentos_get_current_agent()` and
 * `zorem_agentos_is_agent_request()` (plan v1 §15.2).
 *
 * They are defined during boot, never at file load. WordPress loads plugin files
 * alphabetically, so this plugin's files load before the legacy plugin's; a
 * definition at file load would take the names from the legacy plugin while
 * it is active. Boot only happens when the legacy plugin is inactive, so the
 * shims exist exactly when nothing else provides those functions.
 *
 * `zorem_register_skill()` forwards to `ai_agent_os_register_skill()`, which
 * registers nothing since skills were removed in 1.0.1.
 */
final class LegacyApi {

	/**
	 * Define the legacy functions, where nothing has.
	 */
	public static function load(): void {
		require_once __DIR__ . '/legacy-functions.php';
	}
}
