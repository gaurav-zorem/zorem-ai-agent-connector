<?php
/**
 * A reference to whoever is acting.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * Who is acting, in the terms every Agent OS component understands.
 *
 * `ref` is namespaced so references from different providers never collide:
 * `agent:<uuid>` for an AI Agent OS agent, `exp:<slug>` for one of Agent OS
 * Experiences' built-in experiences.
 *
 * `acts_for` answers *who the actor works for*, separately from `type`, which
 * answers *who wrote it*. The legacy plugin let one word carry both meanings
 * and a storefront assistant — written by us, acting for strangers — passed a
 * check meant for admin agents. Access to internal knowledge follows
 * `acts_for`, never `type`.
 */
final class AgentRef {

	/** What the actor acts for. */
	public const ACTS_FOR_ADMIN   = 'admin';
	public const ACTS_FOR_PUBLIC  = 'public';
	public const ACTS_FOR_PARTNER = 'partner';

	/** Who wrote the actor. */
	public const TYPE_INTERNAL = 'internal';
	public const TYPE_EXTERNAL = 'external';

	/**
	 * Namespaced reference, e.g. "agent:1f0…" or "exp:storefront".
	 *
	 * @var string
	 */
	private string $ref;

	/**
	 * Human-readable name.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * One of the ACTS_FOR_* constants.
	 *
	 * @var string
	 */
	private string $acts_for;

	/**
	 * One of the TYPE_* constants.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Constructor.
	 *
	 * @param string $ref      Namespaced reference.
	 * @param string $label    Human-readable name.
	 * @param string $acts_for One of the ACTS_FOR_* constants.
	 * @param string $type     One of the TYPE_* constants.
	 * @throws \InvalidArgumentException When a value is outside its closed set.
	 */
	public function __construct( string $ref, string $label, string $acts_for, string $type = self::TYPE_INTERNAL ) {
		if ( 1 !== preg_match( '/^[a-z]+:[A-Za-z0-9._-]+$/', $ref ) ) {
			throw new \InvalidArgumentException( 'An agent ref must look like "namespace:identifier".' );
		}

		if ( ! in_array( $acts_for, self::acts_for_values(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown acts_for value.' );
		}

		if ( ! in_array( $type, array( self::TYPE_INTERNAL, self::TYPE_EXTERNAL ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown agent type.' );
		}

		$this->ref      = $ref;
		$this->label    = $label;
		$this->acts_for = $acts_for;
		$this->type     = $type;
	}

	/**
	 * Every acts_for value.
	 *
	 * @return array<int, string>
	 */
	public static function acts_for_values(): array {
		return array( self::ACTS_FOR_ADMIN, self::ACTS_FOR_PUBLIC, self::ACTS_FOR_PARTNER );
	}

	/**
	 * Build from an array, validating every field.
	 *
	 * @param array<string, mixed> $data Fields.
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['ref'] ?? '' ),
			(string) ( $data['label'] ?? '' ),
			(string) ( $data['acts_for'] ?? '' ),
			(string) ( $data['type'] ?? self::TYPE_INTERNAL )
		);
	}

	/**
	 * The namespaced reference.
	 */
	public function ref(): string {
		return $this->ref;
	}

	/**
	 * The reference's namespace, e.g. "agent" or "exp".
	 */
	public function namespace(): string {
		return (string) strstr( $this->ref, ':', true );
	}

	/**
	 * The human-readable name.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * What the actor acts for.
	 */
	public function acts_for(): string {
		return $this->acts_for;
	}

	/**
	 * Who wrote the actor.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Whether the actor acts for the site's administrators.
	 */
	public function acts_for_admin(): bool {
		return self::ACTS_FOR_ADMIN === $this->acts_for;
	}

	/**
	 * As an array.
	 *
	 * @return array<string, string>
	 */
	public function to_array(): array {
		return array(
			'ref'      => $this->ref,
			'label'    => $this->label,
			'acts_for' => $this->acts_for,
			'type'     => $this->type,
		);
	}
}
