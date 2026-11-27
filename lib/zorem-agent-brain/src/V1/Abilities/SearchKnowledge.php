<?php
/**
 * The knowledge-search ability.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Abilities;

use ZoremAgentBridge\V1\Security\Fence;
use ZoremAgentBridge\V1\Security\TextRedactor;
use ZoremAgentBrain\V1\Brain;
use ZoremAgentBrain\V1\Store\SearchQuery;

defined( 'ABSPATH' ) || exit;

/**
 * Finds knowledge entries matching a query, within what the reader may read.
 *
 * Returns summaries and keys, never full content. An agent chooses from the
 * summaries and then asks for the one entry it wants, which keeps a broad
 * search from spending the reader's whole context on things it did not need.
 *
 * The query string is untrusted — it comes from a model, which means it comes
 * from whoever last talked to that model — and `SearchQuery` is where that is
 * dealt with: capped, stripped of boolean-mode operators, and escaped for the
 * LIKE fallback. Scoping is applied inside the SQL by `KnowledgeGate`, so the
 * returned total describes readable rows only.
 *
 * Ported from the legacy plugin. No repository import: the gate is the only
 * door, and RetrievalSourceTest asserts it.
 */
final class SearchKnowledge {

	public const NAME = 'agent-brain/knowledge-search';

	/**
	 * Registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'Search knowledge', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Searches the knowledge the acting agent may read and returns matching entries as summaries. Use knowledge-get-entry to read the full text of one result. Search operators such as + - * and quotes are not interpreted; use plain words.', 'zorem-ai-agent-connector' ),
			'category'            => Registrar::CATEGORY,
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'query'   => array(
						'type'        => 'string',
						'maxLength'   => SearchQuery::MAX_LENGTH,
						'description' => __( 'Plain words to search for.', 'zorem-ai-agent-connector' ),
					),
					'plugin'  => array(
						'type'        => 'string',
						'description' => __( 'Optional: restrict to one plugin slug, as returned by knowledge-list-topics.', 'zorem-ai-agent-connector' ),
					),
					'section' => array(
						'type'        => 'string',
						'description' => __( 'Optional: restrict to one section, as returned by knowledge-list-topics.', 'zorem-ai-agent-connector' ),
					),
					'limit'   => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 20,
						'description' => __( 'How many results to return. Default 10.', 'zorem-ai-agent-connector' ),
					),
				),
				'required'   => array( 'query' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'results' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'entry_key'      => array( 'type' => 'string' ),
								'title'          => array( 'type' => 'string' ),
								'summary'        => array( 'type' => 'string' ),
								'plugin'         => array( 'type' => 'string' ),
								'section'        => array( 'type' => 'string' ),
								'token_estimate' => array( 'type' => 'integer' ),
								'provenance'     => array( 'type' => 'object' ),
								'incomplete'     => array( 'type' => 'boolean' ),
							),
						),
					),
					'total'   => array( 'type' => 'integer' ),
				),
			),
			'permission_callback' => static fn(): bool => Brain::may_call_tools(),
			'execute_callback'    => static function ( $input = null ) {
				$reader = Brain::request_reader();
				$gate   = Brain::gate();

				if ( null === $reader ) {
					return Brain::refusal();
				}

				$input = is_array( $input ) ? $input : array();
				$query = new SearchQuery( (string) ( $input['query'] ?? '' ) );

				if ( $query->is_empty() ) {
					return new \WP_Error(
						'zorem_agent_brain_empty_query',
						__( 'A search needs at least one word.', 'zorem-ai-agent-connector' )
					);
				}

				$args = array(
					'per_page' => max( 1, min( 20, (int) ( $input['limit'] ?? 10 ) ) ),
				);

				/*
				 * Filters narrow the search; they cannot widen it. Both are
				 * bound as ordinary parameters and are applied on top of the
				 * agent's scope constraints, never instead of them.
				 */
				if ( ! empty( $input['plugin'] ) ) {
					$args['plugin_slug'] = (string) $input['plugin'];
				}

				if ( ! empty( $input['section'] ) ) {
					$args['section'] = (string) $input['section'];
				}

				$found   = $gate->search( $reader, $query, $args );
				$results = array();

				foreach ( $found['items'] as $entry ) {
					$meta      = $entry->get_meta();
					$results[] = array(
						'entry_key'      => $entry->get_entry_key(),
						// Untrusted text from the entry's source: flattened, unable to open or close a fence.
						'title'          => Fence::plain( TextRedactor::secrets( $entry->get_title() ) ),
						'summary'        => Fence::plain( TextRedactor::secrets( $entry->get_summary() ) ),
						'plugin'         => (string) $entry->get_plugin_slug(),
						'section'        => $entry->get_section(),
						'token_estimate' => $entry->get_token_estimate(),
						'provenance'     => (array) ( $meta['provenance'] ?? array() ),
						'incomplete'     => ! empty( $meta['truncated'] ),
					);
				}

				return array(
					'results' => $results,
					'total'   => (int) $found['total'],
				);
			},
			'meta'                => array(
				'annotations' => array( 'readonly' => true ),
				'zorem'       => array(
					'risk'          => 'read',
					'required_caps' => array(),
				),
				'mcp'         => array(
					'type'   => 'tool',
					'public' => false,
				),
			),
		);
	}
}
