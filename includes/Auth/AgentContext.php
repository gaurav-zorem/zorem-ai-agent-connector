<?php
/**
 * Who is acting on this request.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Auth;

use ZoremAgentBridge\V1\Dto\AgentRef;
use AiAgentOs\Agents\Agent;

defined( 'ABSPATH' ) || exit;

/**
 * The acting identity for the current request.
 *
 * Two layers:
 *
 * - **The authenticated agent** (`set()`): the agent a transport resolved from
 *   a token — MCP or REST. It lasts for the request.
 * - **A run-as stack** (`run_as()`): an identity pushed for one callback and
 *   popped in a `finally`, whatever happens. It holds a bridge `AgentRef`, so it
 *   can carry the agent (`agent:<uuid>`, with its record) or another plugin's
 *   identity, such as another plugin's `exp:storefront`, which has no agent
 *   record here.
 *
 * `get()` answers "which *agent* is acting": the top of the stack when there
 * is one — null for a non-agent identity, which keeps the gate transparent for
 * it — else the authenticated agent. `current_ref()` answers "who is acting",
 * agent or not. This is what Core provides to the bridge as the context
 * (`Bridge\CoreAgentContext`).
 */
final class AgentContext {

	/**
	 * The authenticated agent, for the request.
	 *
	 * @var Agent|null
	 */
	private static ?Agent $agent = null;

	/**
	 * Identities pushed by run_as(), innermost last.
	 *
	 * @var array<int, array{ref: AgentRef, agent: ?Agent}>
	 */
	private static array $stack = array();

	/**
	 * The request id shared by every audit row of this request.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * How the request arrived: mcp, rest, wc_api, cli. '' when unknown.
	 *
	 * @var string
	 */
	private static string $channel = '';

	/**
	 * Why the credential on this request was refused (a Decision reason), '' when none was.
	 *
	 * @var string
	 */
	private static string $credential_problem = '';

	/**
	 * The agent a refused credential was issued to, 0 when it names nobody.
	 *
	 * @var int
	 */
	private static int $credential_problem_agent = 0;

	/**
	 * Set the authenticated agent.
	 *
	 * @param Agent $agent The agent.
	 */
	public static function set( Agent $agent ): void {
		self::$agent = $agent;

		if ( null === self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}
	}

	/**
	 * Run a callback as an identity; restored afterwards, whatever happens.
	 *
	 * @param AgentRef   $ref      The identity.
	 * @param Agent|null $agent    Its agent record, when it is the agent of this plugin.
	 * @param callable   $callback Work.
	 * @return mixed The callback's return value.
	 */
	public static function run_as( AgentRef $ref, ?Agent $agent, callable $callback ) {
		self::$stack[] = array(
			'ref'   => $ref,
			'agent' => $agent,
		);

		try {
			return $callback();
		} finally {
			array_pop( self::$stack );
		}
	}

	/**
	 * The acting agent: the innermost run-as identity's record, else the authenticated agent.
	 */
	public static function get(): ?Agent {
		if ( array() !== self::$stack ) {
			return self::$stack[ count( self::$stack ) - 1 ]['agent'];
		}

		return self::$agent;
	}

	/**
	 * Who is acting, agent or not; null when nobody is.
	 */
	public static function current_ref(): ?AgentRef {
		if ( array() !== self::$stack ) {
			return self::$stack[ count( self::$stack ) - 1 ]['ref'];
		}

		return self::$agent instanceof Agent ? self::$agent->to_agent_ref() : null;
	}

	/**
	 * Whether the agent is acting.
	 */
	public static function has(): bool {
		return self::get() instanceof Agent;
	}

	/**
	 * The acting agent's id, 0 for none.
	 */
	public static function agent_id(): int {
		$agent = self::get();

		return $agent instanceof Agent ? $agent->get_id() : 0;
	}

	/**
	 * The request id, created on first use.
	 */
	public static function request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}

		return self::$request_id;
	}

	/**
	 * Record how the request arrived.
	 *
	 * @param string $channel mcp, rest, wc_api or cli.
	 */
	public static function set_channel( string $channel ): void {
		self::$channel = sanitize_key( $channel );
	}

	/**
	 * How the request arrived, '' when unknown.
	 */
	public static function channel(): string {
		return self::$channel;
	}

	/**
	 * Forget everything. For tests and for the end of a long-running process.
	 */
	public static function reset(): void {
		self::$agent                    = null;
		self::$stack                    = array();
		self::$request_id               = null;
		self::$channel                  = '';
		self::$credential_problem       = '';
		self::$credential_problem_agent = 0;
	}

	/**
	 * Record why this request's credential was refused.
	 *
	 * A refused credential leaves no agent behind, and "no agent" used to be
	 * all the transport could say: a stripped Authorization header, an
	 * unknown token, an expired one and one issued for another address all
	 * looked the same. The resolver that refused it knows which, so it says
	 * so here.
	 *
	 * @param string $reason   A Decision reason.
	 * @param int    $agent_id The agent the credential was issued to, 0 when it names nobody.
	 */
	public static function set_credential_problem( string $reason, int $agent_id = 0 ): void {
		self::$credential_problem       = $reason;
		self::$credential_problem_agent = max( 0, $agent_id );
	}

	/**
	 * Why this request's credential was refused, '' when nothing was recorded.
	 */
	public static function credential_problem(): string {
		return self::$credential_problem;
	}

	/**
	 * The agent a refused credential was issued to, 0 when it names nobody.
	 */
	public static function credential_problem_agent_id(): int {
		return self::$credential_problem_agent;
	}
}
