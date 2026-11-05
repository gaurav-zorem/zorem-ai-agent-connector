<?php
/**
 * The earlier plugin's public functions, forwarding to this one.
 *
 * Loaded by `LegacyApi::load()` during boot only. See that class for why.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'zorem_register_skill' ) ) {
	/**
	 * Register a skill (the legacy name). Forwards to `ai_agent_os_register_skill()`.
	 *
	 * The legacy `source` argument is ignored: the tier is decided by observing
	 * who registered the skill, never by what the caller says it is.
	 *
	 * @param array<string, mixed> $args Skill definition.
	 * @return \WP_Error Always: skills were removed in 1.0.1.
	 */
	function zorem_register_skill( array $args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The legacy plugin's public name, kept for compatibility.
		unset( $args['source'] );

		return ai_agent_os_register_skill( $args );
	}
}

if ( ! function_exists( 'zorem_agentos_get_current_agent' ) ) {
	/**
	 * The agent making the current request, if any (the legacy name).
	 *
	 * Returns this plugin's agent object, whose `get_id()`, `get_name()`,
	 * `get_type()` and `masks_pii()` match the legacy class.
	 *
	 * @return \AiAgentOs\Agents\Agent|null
	 */
	function zorem_agentos_get_current_agent() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The legacy plugin's public name, kept for compatibility.
		return \AiAgentOs\Auth\AgentContext::get();
	}
}

if ( ! function_exists( 'zorem_agentos_is_agent_request' ) ) {
	/**
	 * Whether the current request is made by a governed agent (the legacy name).
	 */
	function zorem_agentos_is_agent_request(): bool { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- The legacy plugin's public name, kept for compatibility.
		return \AiAgentOs\Auth\AgentContext::has();
	}
}
