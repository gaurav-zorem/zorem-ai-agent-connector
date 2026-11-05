<?php
/**
 * A plugin's request for an agent to be created.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * What a plugin would like an agent to be allowed — never what it is allowed.
 *
 * AI Shopping Assistant proposes an agent for each of its experiences. The
 * permission authority decides what to grant: automatically only for abilities
 * it has observed the proposing plugin register, and anything else only after
 * an administrator approves. A plugin can therefore never grant itself access
 * to something it does not own.
 */
final class ProfileProposal {

	/**
	 * Stable slug of the proposed agent, e.g. "storefront".
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * One of the AgentRef::ACTS_FOR_* constants.
	 *
	 * @var string
	 */
	private string $acts_for;

	/**
	 * Ability names requested.
	 *
	 * @var array<int, string>
	 */
	private array $abilities;

	/**
	 * Business Brain collections requested.
	 *
	 * @var array<int, string>
	 */
	private array $collections;

	/**
	 * Slug of the proposing plugin.
	 *
	 * @var string
	 */
	private string $owner;

	/**
	 * Constructor.
	 *
	 * @param string             $slug        Stable slug.
	 * @param string             $label       Human-readable name.
	 * @param string             $acts_for    One of the AgentRef::ACTS_FOR_* constants.
	 * @param array<int, string> $abilities   Ability names requested.
	 * @param array<int, string> $collections Brain collections requested.
	 * @param string             $owner       Slug of the proposing plugin.
	 * @throws \InvalidArgumentException When a field is invalid.
	 */
	public function __construct( string $slug, string $label, string $acts_for, array $abilities, array $collections, string $owner ) {
		if ( '' === $slug || '' === $owner ) {
			throw new \InvalidArgumentException( 'A proposal needs a slug and an owner.' );
		}

		if ( ! in_array( $acts_for, AgentRef::acts_for_values(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown acts_for value.' );
		}

		$this->slug        = $slug;
		$this->label       = $label;
		$this->acts_for    = $acts_for;
		$this->abilities   = array_values( array_unique( array_map( 'strval', $abilities ) ) );
		$this->collections = array_values( array_unique( array_map( 'strval', $collections ) ) );
		$this->owner       = $owner;
	}

	/**
	 * Stable slug.
	 */
	public function slug(): string {
		return $this->slug;
	}

	/**
	 * Human-readable name.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * What the agent would act for.
	 */
	public function acts_for(): string {
		return $this->acts_for;
	}

	/**
	 * Ability names requested.
	 *
	 * @return array<int, string>
	 */
	public function abilities(): array {
		return $this->abilities;
	}

	/**
	 * Brain collections requested.
	 *
	 * @return array<int, string>
	 */
	public function collections(): array {
		return $this->collections;
	}

	/**
	 * Slug of the proposing plugin.
	 */
	public function owner(): string {
		return $this->owner;
	}
}
