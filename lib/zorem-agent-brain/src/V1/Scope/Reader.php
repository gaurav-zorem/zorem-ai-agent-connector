<?php
/**
 * Whoever is reading the Brain.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Scope;

defined( 'ABSPATH' ) || exit;

/**
 * The Brain's view of "who is asking".
 *
 * The Brain must scope knowledge with or without AI Agent OS, so it never
 * asks for an *agent*. It asks for a reader:
 *
 * - `agent:<uuid>` — an AI Agent OS agent, built by Core.
 * - `exp:<slug>`  — one of AI Shopping Assistant' experiences, built by Experiences.
 *
 * `acts_for` decides access to internal knowledge — never who wrote the
 * reader. `plugin_grants` is the set of plugins whose public knowledge the
 * reader may read by derivation (for an agent: the plugins its ability grants
 * belong to). Explicit rules, per reader, live in the scope table.
 */
final class Reader {

	/** What a reader acts for. Same values as the bridge's AgentRef. */
	public const ACTS_FOR_ADMIN   = 'admin';
	public const ACTS_FOR_PUBLIC  = 'public';
	public const ACTS_FOR_PARTNER = 'partner';

	/**
	 * Namespaced reference.
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
	 * Whether the reader may read at all (not revoked, suspended or expired).
	 *
	 * @var bool
	 */
	private bool $active;

	/**
	 * Plugin slugs whose public knowledge is readable by derivation.
	 *
	 * @var array<int, string>
	 */
	private array $plugin_grants;

	/**
	 * Constructor.
	 *
	 * @param string             $ref           Namespaced reference, e.g. "exp:storefront".
	 * @param string             $label         Human-readable name.
	 * @param string             $acts_for      One of the ACTS_FOR_* constants.
	 * @param bool               $active        Whether the reader may read at all.
	 * @param array<int, string> $plugin_grants Plugin slugs readable by derivation.
	 * @throws \InvalidArgumentException When the ref or acts_for is invalid.
	 */
	public function __construct( string $ref, string $label, string $acts_for, bool $active = true, array $plugin_grants = array() ) {
		if ( 1 !== preg_match( '/^[a-z]+:[A-Za-z0-9._-]{1,90}$/', $ref ) ) {
			throw new \InvalidArgumentException( 'A reader ref must look like "namespace:identifier" and fit in 100 characters.' );
		}

		if ( ! in_array( $acts_for, array( self::ACTS_FOR_ADMIN, self::ACTS_FOR_PUBLIC, self::ACTS_FOR_PARTNER ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown acts_for value.' );
		}

		$this->ref           = $ref;
		$this->label         = $label;
		$this->acts_for      = $acts_for;
		$this->active        = $active;
		$this->plugin_grants = array_values( array_unique( array_filter( array_map( 'strval', $plugin_grants ) ) ) );
	}

	/**
	 * Namespaced reference.
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
	 * Human-readable name.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * What the reader acts for.
	 */
	public function acts_for(): string {
		return $this->acts_for;
	}

	/**
	 * Whether the reader acts for the site's administrators.
	 *
	 * The one question every internal-knowledge decision asks — the row check
	 * and the SQL filter both — so the two cannot drift apart.
	 */
	public function reads_internal(): bool {
		return self::ACTS_FOR_ADMIN === $this->acts_for;
	}

	/**
	 * Whether the reader may read at all.
	 */
	public function is_active(): bool {
		return $this->active;
	}

	/**
	 * Plugin slugs readable by derivation.
	 *
	 * @return array<int, string>
	 */
	public function plugin_grants(): array {
		return $this->plugin_grants;
	}
}
