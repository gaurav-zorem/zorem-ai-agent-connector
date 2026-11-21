<?php
/**
 * The agent's MCP address.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The agent's MCP address: `…/mcp/agent/{code}`.
 *
 * The code is random, not the agent's name, so the address names nothing and
 * nothing about the site can be read or guessed from it. It is stored in the
 * agent's meta, created when the agent is set up, and can be replaced if it
 * leaks: the old address then stops working at once. The token still decides
 * who is calling; the address must be the current one
 * (TransportPermission::check_address()).
 */
final class AgentAddress {

	/**
	 * Agent meta key holding the code.
	 */
	public const META = 'mcp_address';

	/**
	 * Length of a code.
	 */
	public const LENGTH = 20;

	/**
	 * A code, as a regular expression without delimiters.
	 */
	public const PATTERN = '[a-z0-9]{20}';

	/**
	 * Route segment before the code.
	 */
	public const SEGMENT = 'agent';

	/**
	 * Agent storage.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agent storage.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * Give the agent its address as soon as it is set up.
	 */
	public function register(): void {
		add_action(
			'ai_agent_os_agent_created',
			function (): void {
				$this->code();
			}
		);
	}

	/**
	 * The agent's code, created and stored if it has none yet; '' before the agent exists.
	 */
	public function code(): string {
		$this->agents->flush_cache();

		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent ) {
			return '';
		}

		$code = (string) $agent->meta( self::META, '' );

		return self::is_code( $code ) ? $code : $this->replace();
	}

	/**
	 * Give the agent a new code. The old address stops working at once.
	 *
	 * @return string The new code, or '' before the agent exists.
	 */
	public function replace(): string {
		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent ) {
			return '';
		}

		$code = self::new_code();

		$this->agents->update_meta( array( self::META => $code ) );

		/**
		 * Fires after the agent is given a new MCP address.
		 *
		 * Every connection signed in at the old one is signed out.
		 *
		 * @param Agent  $agent The agent.
		 * @param string $code  The new address code.
		 */
		do_action( 'ai_agent_os_mcp_address_replaced', $agent, $code );

		return $code;
	}

	/**
	 * The agent's full MCP address, or '' before the agent exists.
	 */
	public function url(): string {
		$code = $this->code();

		return '' === $code ? '' : rest_url( Routes::NAMESPACE . '/' . self::route( $code ) );
	}

	/**
	 * Whether a code is the agent's current address.
	 *
	 * @param string $code Code from an address.
	 */
	public function matches( string $code ): bool {
		if ( ! self::is_code( $code ) ) {
			return false;
		}

		$agent = $this->agents->get();
		$own   = $agent instanceof Agent ? (string) $agent->meta( self::META, '' ) : '';

		return self::is_code( $own ) && hash_equals( $own, $code );
	}

	/**
	 * The REST route of a code's address, below the namespace.
	 *
	 * @param string $code Code.
	 */
	public static function route( string $code ): string {
		return Routes::ROUTE . '/' . self::SEGMENT . '/' . $code;
	}

	/**
	 * The full REST path of a code's address, e.g. /ai-agent-os/v1/mcp/agent/abc….
	 *
	 * @param string $code Code.
	 */
	public static function path( string $code ): string {
		return '/' . Routes::NAMESPACE . '/' . self::route( $code );
	}

	/**
	 * The code in a REST route, when the route is an MCP address; otherwise ''.
	 *
	 * @param string $route REST route, e.g. from WP_REST_Request::get_route().
	 */
	public static function code_in( string $route ): string {
		$pattern = '#^' . preg_quote( strtolower( Routes::path() ), '#' ) . '/' . self::SEGMENT . '/(' . self::PATTERN . ')/?$#';

		return 1 === preg_match( $pattern, strtolower( $route ), $match ) ? $match[1] : '';
	}

	/**
	 * Whether a string is shaped like a code.
	 *
	 * @param string $code Candidate.
	 */
	public static function is_code( string $code ): bool {
		return 1 === preg_match( '/^' . self::PATTERN . '$/', $code );
	}

	/**
	 * A new random code.
	 */
	private static function new_code(): string {
		$alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$code     = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}

		return $code;
	}
}
