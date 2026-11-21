<?php
/**
 * Agent-scoped MCP server construction.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

use AiAgentOs\Agents\Agent;
use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Governance\PermissionResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Builds one MCP server, scoped to the agent making the current request.
 *
 * This is the mechanism behind "permissions the agent doesn't have are
 * invisible", and it works because of how the adapter is ordered:
 *
 *   McpAdapter::instance()  registers rest_api_init at priority 15
 *   McpAdapter::init()      fires mcp_adapter_init, where servers are created
 *   HttpTransport           registers its REST route at priority 16
 *
 * Servers are therefore constructed during the request, after the HTTP headers
 * are readable. We resolve the agent first and register only the tools that
 * agent may use. Ungranted abilities are not filtered out of tools/list — they
 * were never on the server, so tools/list cannot report them, and the agent
 * cannot be argued into calling a tool it has never heard of.
 *
 * That matters because the adapter's own ToolsHandler::list_tools() returns
 * every registered tool without consulting permissions; it only checks them at
 * call time. Relying on the adapter to hide anything would be relying on
 * something it does not do.
 */
final class ServerBuilder {

	/**
	 * The permission authority.
	 *
	 * @var PermissionResolver
	 */
	private PermissionResolver $resolver;

	/**
	 * Transport-level gate.
	 *
	 * @var TransportPermission
	 */
	private TransportPermission $permission;

	/**
	 * The agent's MCP address.
	 *
	 * @var AgentAddress
	 */
	private AgentAddress $addresses;

	/**
	 * Constructor.
	 *
	 * @param PermissionResolver  $resolver   The permission authority.
	 * @param TransportPermission $permission Transport-level gate.
	 * @param AgentAddress        $addresses  The agent's MCP address.
	 */
	public function __construct(
		PermissionResolver $resolver,
		TransportPermission $permission,
		AgentAddress $addresses
	) {
		$this->resolver   = $resolver;
		$this->permission = $permission;
		$this->addresses  = $addresses;
	}

	/**
	 * Create the server for this request.
	 *
	 * @param object $adapter The McpAdapter instance, passed by mcp_adapter_init.
	 */
	public function build( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$agent = AgentContext::get();

		$tools = $agent instanceof Agent ? $this->resolver->granted_ability_names( $agent ) : array();
		// Tools only: this plugin publishes no MCP resources.
		$resources = array();

		/**
		 * Filters the tools the agent's server lists. It may only remove: a tool
		 * the agent holds no grant on never appears, whatever a filter returns.
		 *
		 * @param array<int, string> $tools Granted ability names.
		 * @param Agent|null         $agent The agent.
		 */
		$tools = array_values( array_intersect( (array) apply_filters( 'ai_agent_os_agent_tools', $tools, $agent ), $tools ) );

		/*
		 * The MCP address carries a random code. The server is registered for
		 * whatever well-formed code the URL carries, current or not, so a
		 * replaced or guessed address answers 401 like a wrong token rather
		 * than 404 — the response must not confirm which address is live. The
		 * tools are listed only at the current address; the transport check
		 * refuses every other one before any tool is listed.
		 */
		$code = Routes::requested_agent_code();

		if ( '' !== $code ) {
			// The stored code only: building a server answers a request and must not write an address.
			$stored = $agent instanceof Agent ? (string) $agent->meta( AgentAddress::META, '' ) : '';
			$own    = AgentAddress::is_code( $stored ) && hash_equals( $stored, $code );

			$adapter->create_server(
				Routes::SERVER_ID . '-agent',
				Routes::NAMESPACE,
				AgentAddress::route( $code ),
				AI_AGENT_OS_NAME,
				$this->server_description( $own ? $agent : null ),
				AI_AGENT_OS_VERSION,
				array( '\WP\MCP\Transport\HttpTransport' ),
				AgentOsErrorHandler::class,
				ActivityObservabilityHandler::class,
				$own ? $tools : array(),
				$own ? $resources : array(),
				array(),
				array( $this->permission, 'check_address' )
			);
		}
	}

	/**
	 * The server description shown to a connecting client.
	 *
	 * @param Agent|null $agent The current agent.
	 */
	private function server_description( ?Agent $agent ): string {
		if ( ! $agent instanceof Agent ) {
			return __( 'Governed access to this WordPress site. Authenticate with the agent\'s token to see the abilities it is permitted to use.', 'zorem-ai-agent-connector' );
		}

		return sprintf(
			/* translators: %s: agent name. */
			__( 'Governed access to this WordPress site as the agent "%s". Only abilities granted to it are available.', 'zorem-ai-agent-connector' ),
			$agent->get_name()
		);
	}
}
