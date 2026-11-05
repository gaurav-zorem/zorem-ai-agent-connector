<?php
/**
 * The header brand cluster's content.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_brand' ) ) {
	/**
	 * Brand info for the ZUI header.
	 *
	 * Stated here rather than looked up. The UI library ships a registry of
	 * chrome for the author's whole catalogue, which this plugin neither needs
	 * nor ships: it describes itself, and the badge is empty because there is
	 * no edition to name.
	 *
	 * @return array<string, string>
	 */
	function ai_agent_os_brand(): array {
		return array(
			'name'         => AI_AGENT_OS_NAME,
			'badge'        => '',
			'tagline'      => __( 'Agent governance', 'zorem-ai-agent-connector' ),
			'icon'         => 'shield-check',
			'emblem_bg'    => '#DBEAFE',
			'emblem_color' => '#2563EB',
		);
	}
}
