<?php
/**
 * Public API.
 *
 * This file is the plugin's only public PHP surface, together with the
 * documented `ai_agent_os_*` hooks. Everything else is internal and may
 * change between releases.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_is_running' ) ) {
	/**
	 * Whether this plugin booted on this request.
	 *
	 * False when its requirements are not met — including while the legacy
	 * earlier all-in-one plugin is active, when this plugin deliberately stays inert.
	 */
	function ai_agent_os_is_running(): bool {
		return did_action( 'ai_agent_os_loaded' ) > 0;
	}
}

if ( ! function_exists( 'ai_agent_os_register_skill' ) ) {
	/**
	 * Formerly registered a skill (a manual for a plugin's abilities).
	 *
	 * Skills were removed in 1.0.1. The function stays so a plugin that still
	 * calls it does not fail; it registers nothing.
	 *
	 * @param array<string, mixed> $args Ignored.
	 * @return \WP_Error Always.
	 */
	function ai_agent_os_register_skill( array $args ): \WP_Error {
		unset( $args );

		return new \WP_Error( 'ai_agent_os_skills_removed', __( 'Zorem AI Agent Connector no longer has skills, so nothing was registered.', 'zorem-ai-agent-connector' ) );
	}
}

if ( ! function_exists( 'ai_agent_os_get_current_agent' ) ) {
	/**
	 * The agent making the current request, if any; null for people, which is the common case.
	 *
	 * @return \ZoremAgentBridge\V1\Dto\AgentRef|null
	 */
	function ai_agent_os_get_current_agent(): ?\ZoremAgentBridge\V1\Dto\AgentRef {
		$agent = \AiAgentOs\Auth\AgentContext::get();

		return null !== $agent ? $agent->to_agent_ref() : null;
	}
}

if ( ! function_exists( 'ai_agent_os_is_agent_request' ) ) {
	/**
	 * Whether the current request is made by a governed agent.
	 */
	function ai_agent_os_is_agent_request(): bool {
		return \AiAgentOs\Auth\AgentContext::has();
	}
}
