<?php
/**
 * The agent as a reader of the Website Brain.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Brain;

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Extraction\AbilityPluginMap;
use ZoremAgentBrain\V1\Scope\Reader;
use ZoremAgentBridge\V1\Dto\AgentRef;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Agents\AgentRepository;
use AiAgentOs\Governance\PermissionRepository;

/**
 * Claims the Brain's `agent` reader namespace and builds the agent's reader.
 *
 * - **Who it acts for** comes from the agent's record: only `admin` reads
 *   internal knowledge.
 * - **An inactive agent** (revoked, suspended, expired) gets an inactive
 *   reader, which reads nothing.
 * - **Plugin grants** are derived from its ability grants: an agent granted
 *   any of a plugin's abilities may read that plugin's public documentation
 *   (and all of it when it acts for an administrator). A plugin is identified
 *   by the files the ability was observed registering from, never by its name.
 *
 * Only this plugin builds `agent:*` readers; another plugin claiming the
 * namespace voids both claims and every agent read is refused. The reader is
 * built the same way as in AI Agent OS PRO, which reads the same agent rows.
 */
final class AgentReaders {

	/** The Brain reader namespace this plugin owns. */
	public const NAMESPACE = 'agent';

	/** The owner recorded for the namespace: the same as AI Agent OS PRO's. */
	public const OWNER = 'ai-agent-os';

	/**
	 * The agent.
	 *
	 * @var AgentRepository
	 */
	private AgentRepository $agents;

	/**
	 * The grants.
	 *
	 * @var PermissionRepository
	 */
	private PermissionRepository $permissions;

	/**
	 * Constructor.
	 *
	 * @param AgentRepository      $agents      The agent.
	 * @param PermissionRepository $permissions The grants.
	 */
	public function __construct( AgentRepository $agents, PermissionRepository $permissions ) {
		$this->agents      = $agents;
		$this->permissions = $permissions;
	}

	/**
	 * Claim the namespace.
	 */
	public function register(): void {
		Brain::readers()->register( self::NAMESPACE, array( $this, 'reader' ), self::OWNER );
		Brain::readers()->lists( self::NAMESPACE, array( $this, 'known' ), self::OWNER );
	}

	/**
	 * The agent a Website Brain screen may offer, by label: ref => label.
	 *
	 * An agent that is not active is listed and says so, since a rule given to
	 * it applies once it is active again.
	 *
	 * @return array<string, string>
	 */
	public function known(): array {
		$agent = $this->agents->get();

		if ( ! $agent instanceof Agent ) {
			return array();
		}

		$label = $agent->get_name();

		if ( ! $agent->is_active() ) {
			/* translators: %s: agent name. */
			$label = sprintf( __( '%s (not active)', 'zorem-ai-agent-connector' ), $label );
		}

		return array( $agent->ref() => $label );
	}

	/**
	 * The reader for an agent reference, or null for one this plugin does not know.
	 *
	 * @param AgentRef $ref The acting identity.
	 */
	public function reader( AgentRef $ref ): ?Reader {
		if ( 0 !== strpos( $ref->ref(), Agent::REF_PREFIX ) ) {
			return null;
		}

		$agent = $this->agents->get_by_ref( $ref->ref() );

		if ( ! $agent instanceof Agent ) {
			return null;
		}

		return $this->for_agent( $agent );
	}

	/**
	 * The reader for an agent record.
	 *
	 * @param Agent $agent The agent.
	 */
	public function for_agent( Agent $agent ): Reader {
		return new Reader( $agent->ref(), $agent->get_name(), $agent->get_acts_for(), $agent->is_active(), $this->plugin_grants( $agent ) );
	}

	/**
	 * Plugins whose abilities the agent holds a grant on.
	 *
	 * @param Agent $agent The agent.
	 * @return array<int, string>
	 */
	private function plugin_grants( Agent $agent ): array {
		$granted = array();

		foreach ( $this->permissions->get_grants( $agent->get_id() ) as $ability => $level ) {
			if ( PermissionRepository::LEVEL_NONE !== $level ) {
				$granted[] = (string) $ability;
			}
		}

		/*
		 * Through the Brain's ability-to-plugin map, never the origin recorder
		 * directly: the recorder holds the file that registered an ability, and
		 * a file path matches no entry's plugin slug. The map resolves that file
		 * to the installed plugin it is in.
		 */
		return Brain::get( AbilityPluginMap::class )->plugins_for( $granted );
	}
}
