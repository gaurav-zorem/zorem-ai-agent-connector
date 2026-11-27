<?php
/**
 * The knowledge-topics ability.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Abilities;

use ZoremAgentBrain\V1\Brain;

defined( 'ABSPATH' ) || exit;

/**
 * Tells an agent what the Brain knows about, and what reading it would cost.
 *
 * This is the entry point an agent should call first: it is a map, not content.
 * Every row is a plugin and section the reader may actually read, with a token
 * estimate so a model can decide what fits in the context it has left rather
 * than fetching an entry and discovering it was too big.
 *
 * There is no repository here, deliberately. Everything reaches storage through
 * `KnowledgeGate` — the class that applies reader scoping and records refusals —
 * so there is exactly one path to knowledge and it is the audited one. An
 * import of `KnowledgeRepository` in this file would be a second path.
 */
final class ListTopics {

	public const NAME = 'agent-brain/knowledge-list-topics';

	/**
	 * Registration arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function definition(): array {
		return array(
			'label'               => __( 'List knowledge topics', 'zorem-ai-agent-connector' ),
			'description'         => __( 'Lists the plugins and sections the acting agent may read, with an entry count and token estimate for each. Call this before searching or reading, to see what is available and what it costs.', 'zorem-ai-agent-connector' ),
			'category'            => Registrar::CATEGORY,
			'input_schema'        => array(),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'topics'         => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'plugin'         => array( 'type' => 'string' ),
								'section'        => array( 'type' => 'string' ),
								'entries'        => array( 'type' => 'integer' ),
								'token_estimate' => array( 'type' => 'integer' ),
							),
						),
					),
					'total_entries'  => array( 'type' => 'integer' ),
					'total_tokens'   => array( 'type' => 'integer' ),
					'estimate_basis' => array( 'type' => 'string' ),
				),
			),
			'permission_callback' => static fn(): bool => Brain::may_call_tools(),
			'execute_callback'    => static function () {
				$reader = Brain::request_reader();
				$gate   = Brain::gate();

				if ( null === $reader ) {
					return Brain::refusal();
				}

				$topics  = array();
				$entries = 0;
				$tokens  = 0;

				foreach ( $gate->topics( $reader ) as $row ) {
					$row_entries = (int) ( $row['entries'] ?? 0 );
					$row_tokens  = (int) ( $row['tokens'] ?? 0 );

					$topics[] = array(
						'plugin'         => (string) ( $row['plugin_slug'] ?? '' ),
						'section'        => (string) ( $row['section'] ?? '' ),
						'entries'        => $row_entries,
						'token_estimate' => $row_tokens,
					);

					$entries += $row_entries;
					$tokens  += $row_tokens;
				}

				return array(
					'topics'         => $topics,
					'total_entries'  => $entries,
					'total_tokens'   => $tokens,

					/*
					 * Said plainly rather than left for the model to assume: an
					 * agent budgeting its context should know this is an
					 * approximation and roughly how good a one.
					 */
					'estimate_basis' => 'approximate: subword estimate, within ~30% of a tokenizer',
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
