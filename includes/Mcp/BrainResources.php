<?php
/**
 * Website Brain entries as MCP resources.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

defined( 'ABSPATH' ) || exit;

use ZoremAgentBridge\V1\Security\Fence;
use ZoremAgentBridge\V1\Security\TextRedactor;
use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use AiAgentOs\Agents\Agent;
use AiAgentOs\Auth\AgentContext;
use AiAgentOs\Brain\AgentReaders;

/**
 * Lets the agent's MCP client read Brain entries as resources (the same as in
 * AI Agent OS PRO, which reads the same rows).
 *
 * The MCP Adapter projects abilities into resources, so each approved entry is
 * registered as an ability (`ai-agent-os/kb-…`), up to `MAX_RESOURCES`. The
 * agent's server lists only the entries **its reader** may read (the Brain's
 * gate decides), and reading one checks the gate again for the acting agent:
 * the list is a convenience, the read is the boundary.
 */
final class BrainResources {

	/** Most entries registered, and listed per agent. */
	public const MAX_RESOURCES = 200;

	/** The ability category. */
	public const CATEGORY = 'ai-agent-os';

	/**
	 * Agents' Brain readers.
	 *
	 * @var AgentReaders
	 */
	private AgentReaders $readers;

	/**
	 * Entry keys already registered, by key.
	 *
	 * @var array<string, string>
	 */
	private array $registered = array();

	/**
	 * Constructor.
	 *
	 * @param AgentReaders $readers Agents' Brain readers.
	 */
	public function __construct( AgentReaders $readers ) {
		$this->readers = $readers;
	}

	/**
	 * Hook registration.
	 *
	 * The `ai-agent-os` category is registered with the plugin's own
	 * categories (`CoreAbilities::categories()`).
	 */
	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_entries' ), 100 );
	}

	/**
	 * Register approved entries as resource abilities.
	 */
	public function register_entries(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$entries = Brain::repository()->query(
			array(
				'trust_status' => KnowledgeEntry::TRUST_APPROVED,
				'per_page'     => self::MAX_RESOURCES,
			)
		);

		foreach ( $entries['items'] as $entry ) {
			$this->register_entry( $entry );
		}
	}

	/**
	 * The resource ability names an agent's server lists: readable by its reader and registered.
	 *
	 * @param Agent $agent The agent.
	 * @return array<int, string>
	 */
	public function resources_for( Agent $agent ): array {
		$names  = array();
		$result = Brain::gate()->query( $this->readers->for_agent( $agent ), array( 'per_page' => self::MAX_RESOURCES ) );

		foreach ( $result['items'] as $entry ) {
			$name = self::ability_name( $entry->get_entry_key() );

			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * The ability name for an entry: readable, unique, within the name limits.
	 *
	 * @param string $entry_key Entry key.
	 */
	public static function ability_name( string $entry_key ): string {
		$slug = trim( strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '-', $entry_key ) ), '-' );

		return 'ai-agent-os/kb-' . substr( $slug, 0, 40 ) . '-' . substr( md5( $entry_key ), 0, 8 );
	}

	/**
	 * The resource URI for an entry.
	 *
	 * @param string $entry_key Entry key.
	 */
	public static function resource_uri( string $entry_key ): string {
		// phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- URI scheme, lowercase by convention.
		return 'wordpress://ai-agent-os/knowledge/' . rawurlencode( $entry_key );
	}

	/**
	 * Register one entry.
	 *
	 * @param KnowledgeEntry $entry The entry.
	 * @return string|null The ability name, or null when registration failed.
	 */
	public function register_entry( KnowledgeEntry $entry ): ?string {
		$key  = $entry->get_entry_key();
		$name = self::ability_name( $key );

		if ( isset( $this->registered[ $key ] ) || ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) ) {
			$this->registered[ $key ] = $name;

			return $name;
		}

		$uri         = self::resource_uri( $key );
		$readers     = $this->readers;
		$title       = Fence::plain( TextRedactor::secrets( $entry->get_title() ) );
		$summary     = Fence::plain( TextRedactor::secrets( $entry->get_summary() ) );
		$description = '' !== trim( $summary ) ? $summary : ( '' !== trim( $title ) ? $title : $key );

		$registered = wp_register_ability(
			$name,
			array(
				'label'               => '' !== trim( $title ) ? $title : $key,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => array(),
				'output_schema'       => array(),
				'permission_callback' => static fn(): bool => AgentContext::has(),
				'execute_callback'    => static function () use ( $key, $uri, $readers ) {
					$agent = AgentContext::get();
					$read  = $agent instanceof Agent ? Brain::gate()->get( $readers->for_agent( $agent ), $key ) : null;

					// Read through the gate for the acting agent, not from what was registered.
					if ( null === $read ) {
						return new \WP_Error( 'ai_agent_os_resource_not_readable', __( 'That entry is not available to this agent.', 'zorem-ai-agent-connector' ) );
					}

					return array(
						array(
							'uri'      => $uri,
							'mimeType' => 'text/markdown',
							// Fenced and stripped of secrets: the entry's source is untrusted text.
							'text'     => Fence::wrap( TextRedactor::secrets( $read->get_content() ) ),
						),
					);
				},
				'meta'                => array(
					'uri'         => $uri,
					'annotations' => array( 'readonly' => true ),
					'zorem'       => array(
						'risk'      => 'read',
						'entry_key' => $key,
					),
					'mcp'         => array(
						'type'   => 'resource',
						'public' => false,
					),
				),
			)
		);

		if ( null === $registered ) {
			return null;
		}

		$this->registered[ $key ] = $name;

		return $name;
	}
}
