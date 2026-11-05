<?php
/**
 * Service access for templates.
 *
 * A screen file is not a class and has no constructor to inject into, so it
 * needs some way to reach the container. This is that way, and it is
 * deliberately the only one: a template that calls `new SomeService()` builds a
 * second instance with none of the wiring, and the bug shows up as a cache that
 * is mysteriously always cold.
 *
 * @package AiAgentOs
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ai_agent_os_service' ) ) {
	/**
	 * Resolve a service from the plugin container.
	 *
	 * @param string $id Fully-qualified class name.
	 * @return mixed
	 */
	function ai_agent_os_service( string $id ) {
		return \AiAgentOs\Plugin::instance()->container()->get( $id );
	}
}

if ( ! function_exists( 'ai_agent_os_bytes' ) ) {
	/**
	 * Human-readable bytes.
	 *
	 * The React screen printed the raw integer — `String( memory.available )` —
	 * which is accurate and unreadable. `size_format` is core's, so the units
	 * are already translated.
	 *
	 * @param int $bytes Byte count.
	 */
	function ai_agent_os_bytes( int $bytes ): string {
		if ( $bytes <= 0 ) {
			return '0 B';
		}

		return (string) size_format( $bytes, 1 );
	}
}
