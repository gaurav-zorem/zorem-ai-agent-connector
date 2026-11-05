<?php
/**
 * Input and output schema pieces shared by the core abilities.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Abilities\Support;

defined( 'ABSPATH' ) || exit;

use AiAgentOs\Governance\RiskClassifier;

/**
 * Builds closed schemas (CS-AI-2) so each ability states only what is its own.
 */
final class Schema {

	/**
	 * A closed input object.
	 *
	 * An object with nothing required gets an empty default, so a call with no
	 * input at all is valid.
	 *
	 * @param array<string, array<string, mixed>> $properties Property schemas.
	 * @param array<int, string>                  $required   Required property names.
	 * @return array<string, mixed>
	 */
	public static function input( array $properties, array $required = array() ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( array() === $required ) {
			$schema['default'] = array();
		} else {
			$schema['required'] = $required;
		}

		return $schema;
	}

	/**
	 * A positive ID.
	 *
	 * @param string $description What the ID identifies, as an instruction.
	 * @return array<string, mixed>
	 */
	public static function id( string $description ): array {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => $description,
		);
	}

	/**
	 * A slug-shaped name: a post type, taxonomy, role or meta key.
	 *
	 * @param string $description What the name identifies, as an instruction.
	 * @return array<string, mixed>
	 */
	public static function key( string $description ): array {
		return array(
			'type'        => 'string',
			'minLength'   => 1,
			'maxLength'   => Limits::KEY_MAX,
			'description' => $description,
		);
	}

	/**
	 * A search phrase.
	 *
	 * @return array<string, mixed>
	 */
	public static function search(): array {
		return array(
			'type'        => 'string',
			'maxLength'   => Limits::SEARCH_MAX,
			'description' => __( 'Words to search for. Leave out to list everything.', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * The `page` and `per_page` properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function paging(): array {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => Limits::PAGE_MAX,
				'default'     => 1,
				'description' => __( 'Page of results, starting at 1. Ask for the next page only while it is no higher than the returned "pages".', 'zorem-ai-agent-connector' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => Limits::PER_PAGE_MAX,
				'default'     => Limits::PER_PAGE_DEFAULT,
				'description' => __( 'Results per page.', 'zorem-ai-agent-connector' ),
			),
		);
	}

	/**
	 * The paging fields every list result carries, plus its items.
	 *
	 * @param string               $items_key The key the items are under.
	 * @param array<string, mixed> $item      One item's schema.
	 * @return array<string, mixed>
	 */
	public static function page_of( string $items_key, array $item ): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				$items_key => array(
					'type'  => 'array',
					'items' => $item,
				),
				'total'    => array( 'type' => 'integer' ),
				'pages'    => array( 'type' => 'integer' ),
				'page'     => array( 'type' => 'integer' ),
				'per_page' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * `page` and `per_page` from an input, clamped to the limits again.
	 *
	 * @param array<string, mixed> $input Ability input.
	 * @return array{0: int, 1: int} Page and per page.
	 */
	public static function paging_from( array $input ): array {
		$page     = (int) ( $input['page'] ?? 1 );
		$per_page = (int) ( $input['per_page'] ?? Limits::PER_PAGE_DEFAULT );

		return array(
			max( 1, min( Limits::PAGE_MAX, $page ) ),
			max( 1, min( Limits::PER_PAGE_MAX, $per_page ) ),
		);
	}

	/**
	 * A list result.
	 *
	 * @param string            $items_key The key the items go under.
	 * @param array<int, mixed> $items     The items.
	 * @param int               $total     Matching items across all pages.
	 * @param int               $page      This page.
	 * @param int               $per_page  Items per page.
	 * @return array<string, mixed>
	 */
	public static function page_result( string $items_key, array $items, int $total, int $page, int $per_page ): array {
		return array(
			$items_key => array_values( $items ),
			'total'    => $total,
			'pages'    => (int) ceil( $total / max( 1, $per_page ) ),
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * The annotations and this plugin's meta every core ability carries.
	 *
	 * @param string             $risk          A RiskClassifier risk.
	 * @param array<int, string> $required_caps Capabilities lent for the call.
	 * @return array<string, mixed>
	 */
	public static function meta( string $risk, array $required_caps ): array {
		$read = RiskClassifier::RISK_READ === $risk;

		return array(
			'annotations' => array(
				'readonly'    => $read,
				'destructive' => RiskClassifier::RISK_DESTRUCTIVE === $risk,
				'idempotent'  => $read,
			),
			'zorem'       => array(
				'risk'          => $risk,
				'required_caps' => $required_caps,
			),
			'mcp'         => array(
				'type'   => 'tool',
				'public' => false,
			),
		);
	}
}
