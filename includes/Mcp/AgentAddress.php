<?php
/**
 * The agent's own MCP address.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Core\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * An MCP address that belongs to the agent: `…/mcp/agent/{code}`.
 *
 * Why a second kind of address: a client such as Claude keeps one connection
 * per URL, and this one is the agent's, so a connector added here is its own
 * server, signed in as the agent, with the agent's tools only.
 *
 * The code is random, not the agent's name, so an address names nothing and
 * nothing about the site can be read or guessed from it. It is stored in the
 * agent's meta, created the first time it is asked for, and can be replaced
 * if it leaks. The token still decides who is calling; the address only
 * decides who may call there (TransportPermission::check_agent()).
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
	 * Agents.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository $agents Agents.
	 */
	public function __construct( AgentRepository $agents ) {
		$this->agents = $agents;
	}

	/**
	 * Give every new agent its address straight away.
	 */
	public function register(): void {
		add_action(
			'ai_agent_os_agent_created',
			function ( Agent $agent ): void {
				$this->code( $agent );
			}
		);
	}

	/**
	 * The agent's code, created and stored if it has none yet.
	 *
	 * @param Agent $agent The agent.
	 */
	public function code( Agent $agent ): string {
		$code = (string) $agent->meta( self::META, '' );

		if ( self::is_code( $code ) ) {
			return $code;
		}

		/*
		 * The object may predate its code. The agent AgentFactory returns is
		 * given one on `ai_agent_os_agent_created`, which stores it without
		 * touching that object, so the first url() on it used to mint a second
		 * address and sign out every connection made at the first. The stored
		 * agent decides; only an agent that truly has no code gets one.
		 */
		$stored = $this->agents->find( $agent->get_id() );
		$code   = $stored instanceof Agent ? (string) $stored->meta( self::META, '' ) : '';

		if ( self::is_code( $code ) ) {
			return $code;
		}

		return $this->replace( $agent );
	}

	/**
	 * Give the agent a new code. The old address stops working at once.
	 *
	 * @param Agent $agent The agent.
	 * @return string The new code.
	 */
	public function replace( Agent $agent ): string {
		$code = self::new_code();

		$this->agents->update_meta( $agent->get_id(), array( self::META => $code ) );

		/**
		 * Fires after an agent is given a new MCP address.
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
	 * The agent's full MCP address.
	 *
	 * @param Agent $agent The agent.
	 */
	public function url( Agent $agent ): string {
		return rest_url( Routes::NAMESPACE . '/' . self::route( $this->code( $agent ) ) );
	}

	/**
	 * The agent whose address this code is, or null.
	 *
	 * @param string $code Code from an address.
	 */
	public function agent_for( string $code ): ?Agent {
		global $wpdb;

		if ( ! self::is_code( $code ) ) {
			return null;
		}

		$table = Installer::table( Installer::TABLE_AGENTS );
		$like  = '%' . $wpdb->esc_like( '"' . self::META . '":"' . $code . '"' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.WP.PreparedSQL -- Internal table name; value bound.
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE meta LIKE %s LIMIT 1', $table, $like ) );

		if ( $id < 1 ) {
			return null;
		}

		$agent = $this->agents->find( $id );

		// The LIKE narrowed it; the stored value decides.
		return $agent instanceof Agent && hash_equals( (string) $agent->meta( self::META, '' ), $code ) ? $agent : null;
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
	 * The code in a REST route, when the route is an agent's address; otherwise ''.
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
