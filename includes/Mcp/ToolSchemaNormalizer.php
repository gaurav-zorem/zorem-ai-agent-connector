<?php
/**
 * Valid JSON Schema in the MCP tool list.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * Makes every tool schema in a tools/list answer valid JSON Schema.
 *
 * The incident: ChatGPT signed in and then showed "No tools available for this
 * app" for an agent with 47 tools. PHP encodes an empty array as a JSON list,
 * so a tool with no inputs published `"properties": []`, and every tool with no
 * required field published `"default": []` on an object schema. Claude reads
 * past both; ChatGPT validates tool schemas strictly and dropped the list.
 *
 * The ability's own schema is left alone: WordPress validates input against
 * it with array access, and an object there would break that. Only the copy
 * sent to the client is repaired, after the adapter has built the answer, so
 * every tool is covered — this plugin's, WooCommerce's and any other plugin's —
 * whichever copy of the MCP Adapter is loaded.
 *
 * What is repaired, at any depth:
 *   - an empty `properties`, `patternProperties`, `$defs`, `definitions` or
 *     `dependentSchemas` becomes `{}`;
 *   - an empty schema where a schema belongs (`items`, `additionalProperties`,
 *     `not`, …) becomes `{}`, which accepts anything, as `[]` was meant to;
 *   - an empty `default` on an object schema becomes `{}`;
 *   - a draft-3 `"required": true` inside a property (WordPress REST style)
 *     moves into the parent's `required` list, where JSON Schema expects it.
 */
final class ToolSchemaNormalizer {

	/**
	 * Keywords whose value maps names to schemas.
	 */
	private const SCHEMA_MAPS = array( 'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas' );

	/**
	 * Keywords whose value is one schema (or, for `items`, a draft-4 tuple of them).
	 */
	private const SCHEMA_VALUES = array( 'items', 'additionalItems', 'additionalProperties', 'contains', 'not', 'propertyNames', 'if', 'then', 'else', 'unevaluatedItems', 'unevaluatedProperties' );

	/**
	 * Keywords whose value is a list of schemas.
	 */
	private const SCHEMA_LISTS = array( 'anyOf', 'oneOf', 'allOf', 'prefixItems' );

	/**
	 * Hook the repair.
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'filter_response' ), 10, 3 );
	}

	/**
	 * Repair the tool schemas in a tools/list answer on the agent's MCP address.
	 *
	 * @param mixed            $response The response.
	 * @param \WP_REST_Server  $server   Server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function filter_response( $response, $server, $request ) {
		unset( $server );

		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request || ! Routes::is_mcp_route( $request->get_route() ) ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || ! isset( $data['result'] ) ) {
			return $response;
		}

		$result = $data['result'];
		$tools  = is_object( $result ) ? ( $result->tools ?? null ) : ( is_array( $result ) ? ( $result['tools'] ?? null ) : null );

		if ( ! is_array( $tools ) ) {
			return $response;
		}

		$tools = $this->normalize_tools( $tools );

		if ( is_object( $result ) ) {
			$result->tools = $tools;
		} else {
			$result['tools'] = $tools;
		}

		$data['result'] = $result;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * Repair the schemas of a list of tools.
	 *
	 * @param array<int|string, mixed> $tools Tools, as the adapter lists them.
	 * @return array<int|string, mixed>
	 */
	public function normalize_tools( array $tools ): array {
		foreach ( $tools as $index => $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			if ( array_key_exists( 'inputSchema', $tool ) ) {
				$tool['inputSchema'] = $this->normalize_tool_schema( $tool['inputSchema'] );
			}

			if ( array_key_exists( 'outputSchema', $tool ) ) {
				$tool['outputSchema'] = $this->normalize_tool_schema( $tool['outputSchema'] );
			}

			$tools[ $index ] = $tool;
		}

		return $tools;
	}

	/**
	 * Repair a tool's top-level input or output schema, which MCP requires to be an object schema.
	 *
	 * @param mixed $schema The schema.
	 * @return mixed
	 */
	public function normalize_tool_schema( $schema ) {
		if ( array() === $schema ) {
			return array(
				'type'       => 'object',
				'properties' => new \stdClass(),
			);
		}

		return $this->normalize_schema( $schema );
	}

	/**
	 * Repair one schema and everything inside it.
	 *
	 * @param mixed $schema The schema.
	 * @return mixed
	 */
	public function normalize_schema( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		// An empty schema accepts anything; as JSON it must be {}, not [].
		if ( array() === $schema ) {
			return new \stdClass();
		}

		// A list is not a schema; nothing here to repair.
		if ( self::is_list( $schema ) ) {
			return $schema;
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && ! self::is_list( $schema['properties'] ) ) {
			$schema = $this->lift_boolean_required( $schema );
		}

		foreach ( self::SCHEMA_MAPS as $keyword ) {
			if ( ! isset( $schema[ $keyword ] ) || ! is_array( $schema[ $keyword ] ) ) {
				continue;
			}

			if ( array() === $schema[ $keyword ] ) {
				$schema[ $keyword ] = new \stdClass();
				continue;
			}

			if ( self::is_list( $schema[ $keyword ] ) ) {
				continue;
			}

			foreach ( $schema[ $keyword ] as $name => $sub ) {
				$schema[ $keyword ][ $name ] = $this->normalize_schema( $sub );
			}
		}

		foreach ( self::SCHEMA_VALUES as $keyword ) {
			if ( ! isset( $schema[ $keyword ] ) || ! is_array( $schema[ $keyword ] ) ) {
				continue;
			}

			$schema[ $keyword ] = array() !== $schema[ $keyword ] && self::is_list( $schema[ $keyword ] )
				? array_map( array( $this, 'normalize_schema' ), $schema[ $keyword ] )
				: $this->normalize_schema( $schema[ $keyword ] );
		}

		foreach ( self::SCHEMA_LISTS as $keyword ) {
			if ( isset( $schema[ $keyword ] ) && is_array( $schema[ $keyword ] ) && self::is_list( $schema[ $keyword ] ) ) {
				$schema[ $keyword ] = array_map( array( $this, 'normalize_schema' ), $schema[ $keyword ] );
			}
		}

		if ( array_key_exists( 'default', $schema ) && array() === $schema['default'] && self::is_object_type( $schema['type'] ?? null ) ) {
			$schema['default'] = new \stdClass();
		}

		return $schema;
	}

	/**
	 * Move draft-3 `"required": true` from the properties into the parent's `required` list.
	 *
	 * @param array<string, mixed> $schema An object schema with properties.
	 * @return array<string, mixed>
	 */
	private function lift_boolean_required( array $schema ): array {
		$required = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
		$changed  = false;

		foreach ( $schema['properties'] as $name => $property ) {
			if ( ! is_array( $property ) || ! array_key_exists( 'required', $property ) || ! is_bool( $property['required'] ) ) {
				continue;
			}

			if ( true === $property['required'] && ! in_array( (string) $name, $required, true ) ) {
				$required[] = (string) $name;
			}

			unset( $property['required'] );
			$schema['properties'][ $name ] = $property;
			$changed                       = true;
		}

		if ( $changed ) {
			// An object-level boolean `required` (draft 3) is replaced too; an empty list is left out.
			unset( $schema['required'] );

			if ( array() !== $required ) {
				$schema['required'] = array_values( $required );
			}
		}

		return $schema;
	}

	/**
	 * Whether an array is a list (keys 0, 1, 2, … in order).
	 *
	 * The plugin requires PHP 8.0, so PHP 8.1's array_is_list() is not used.
	 *
	 * @param array<int|string, mixed> $value The array.
	 */
	private static function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Whether a schema's `type` is only "object".
	 *
	 * @param mixed $type The `type` keyword.
	 */
	private static function is_object_type( $type ): bool {
		if ( 'object' === $type ) {
			return true;
		}

		return is_array( $type ) && in_array( 'object', $type, true ) && ! in_array( 'array', $type, true );
	}
}
