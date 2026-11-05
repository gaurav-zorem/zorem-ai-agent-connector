<?php
/**
 * The abilities an actor may use.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * An immutable set of ability names.
 */
final class ToolSet {

	/**
	 * Ability names, unique, in the order given.
	 *
	 * @var array<int, string>
	 */
	private array $names;

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $names Ability names.
	 */
	public function __construct( array $names ) {
		$this->names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
	}

	/**
	 * An empty set.
	 */
	public static function none(): self {
		return new self( array() );
	}

	/**
	 * Whether the set holds an ability.
	 *
	 * @param string $name Ability name.
	 */
	public function contains( string $name ): bool {
		return in_array( $name, $this->names, true );
	}

	/**
	 * The ability names.
	 *
	 * @return array<int, string>
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * How many abilities the set holds.
	 */
	public function count(): int {
		return count( $this->names );
	}
}
