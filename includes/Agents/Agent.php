<?php
/**
 * Agent entity.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Agents;

use ZoremAgentBridge\V1\Dto\AgentRef;

defined( 'ABSPATH' ) || exit;

/**
 * An AI agent: an identity that is not a person.
 *
 * Immutable. Persistence lives in AgentRepository; this class only answers
 * questions about a single agent's state.
 */
final class Agent {

	public const TYPE_INTERNAL = 'internal';
	public const TYPE_EXTERNAL = 'external';

	/**
	 * Who the agent acts for: the one question that decides what an agent may
	 * read and whether it sees customer data. There used to be a separate
	 * "type" (internal / external) as well; two answers to nearly the same
	 * question once let a storefront agent read internal knowledge, so only
	 * this one is kept.
	 */
	public const ACTS_FOR_ADMIN   = AgentRef::ACTS_FOR_ADMIN;
	public const ACTS_FOR_PUBLIC  = AgentRef::ACTS_FOR_PUBLIC;
	public const ACTS_FOR_PARTNER = AgentRef::ACTS_FOR_PARTNER;

	/** The prefix of an agent's reference on the bridge and in the Brain. */
	public const REF_PREFIX = 'agent:';

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_REVOKED   = 'revoked';
	public const STATUS_SUSPENDED = 'suspended';

	/**
	 * Id.
	 *
	 * @var int
	 */
	private int $id;
	/**
	 * Uuid.
	 *
	 * @var string
	 */
	private string $uuid;
	/**
	 * Name.
	 *
	 * @var string
	 */
	private string $name;
	/**
	 * Slug.
	 *
	 * @var string
	 */
	private string $slug;
	/**
	 * Status.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Who the agent acts for: admin, public or partner.
	 *
	 * @var string
	 */
	private string $acts_for;

	/**
	 * Auth method.
	 *
	 * @var string
	 */
	private string $auth_method;
	/**
	 * Token prefix.
	 *
	 * @var string|null
	 */
	private ?string $token_prefix;
	/**
	 * Token created at.
	 *
	 * @var string|null
	 */
	private ?string $token_created_at;
	/**
	 * Token last used at.
	 *
	 * @var string|null
	 */
	private ?string $token_last_used_at;
	/**
	 * Shadow user id.
	 *
	 * @var int
	 */
	private int $shadow_user_id;
	/**
	 * Expires at.
	 *
	 * @var string|null
	 */
	private ?string $expires_at;
	/**
	 * Created by.
	 *
	 * @var int
	 */
	private int $created_by;
	/**
	 * Created at.
	 *
	 * @var string
	 */
	private string $created_at;
	/**
	 * Updated at.
	 *
	 * @var string
	 */
	private string $updated_at;

	/**
	 * Free-form metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $meta;

	/**
	 * Construct from a database row.
	 *
	 * @param array<string, mixed> $row Row from the agents table.
	 */
	public function __construct( array $row ) {
		$this->id                 = (int) ( $row['id'] ?? 0 );
		$this->uuid               = (string) ( $row['uuid'] ?? '' );
		$this->name               = (string) ( $row['name'] ?? '' );
		$this->slug               = (string) ( $row['slug'] ?? '' );
		$this->status             = (string) ( $row['status'] ?? self::STATUS_ACTIVE );
		$this->acts_for           = self::normalize_acts_for( (string) ( $row['acts_for'] ?? '' ) );
		$this->auth_method        = (string) ( $row['auth_method'] ?? 'bearer' );
		$this->token_prefix       = isset( $row['token_prefix'] ) ? (string) $row['token_prefix'] : null;
		$this->token_created_at   = isset( $row['token_created_at'] ) ? (string) $row['token_created_at'] : null;
		$this->token_last_used_at = isset( $row['token_last_used_at'] ) ? (string) $row['token_last_used_at'] : null;
		$this->shadow_user_id     = (int) ( $row['shadow_user_id'] ?? 0 );
		$this->expires_at         = ! empty( $row['expires_at'] ) ? (string) $row['expires_at'] : null;
		$this->created_by         = (int) ( $row['created_by'] ?? 0 );
		$this->created_at         = (string) ( $row['created_at'] ?? '' );
		$this->updated_at         = (string) ( $row['updated_at'] ?? '' );

		$meta       = isset( $row['meta'] ) && is_string( $row['meta'] ) ? json_decode( $row['meta'], true ) : $row['meta'] ?? array();
		$this->meta = is_array( $meta ) ? $meta : array();
	}

	/**
	 * Agent id.
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Public identifier, safe to share with an integrator.
	 */
	public function get_uuid(): string {
		return $this->uuid;
	}

	/**
	 * Human-readable name.
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * URL-safe identifier.
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Internal or external, derived from who the agent acts for.
	 *
	 * Kept only for the legacy `zorem_agentos_get_current_agent()` object and
	 * the bridge's agent description. Nothing decides on it; decisions use
	 * acts_for.
	 */
	public function get_type(): string {
		return self::ACTS_FOR_ADMIN === $this->acts_for ? self::TYPE_INTERNAL : self::TYPE_EXTERNAL;
	}

	/**
	 * Lifecycle status.
	 */
	public function get_status(): string {
		return $this->status;
	}

	/**
	 * Who the agent acts for.
	 */
	public function get_acts_for(): string {
		return $this->acts_for;
	}

	/**
	 * The agent's reference: `agent:<uuid>`.
	 */
	public function ref(): string {
		return self::REF_PREFIX . $this->uuid;
	}

	/**
	 * The agent as the bridge describes it.
	 */
	public function to_agent_ref(): AgentRef {
		return new AgentRef( $this->ref(), $this->name, $this->acts_for, self::TYPE_EXTERNAL === $this->get_type() ? AgentRef::TYPE_EXTERNAL : AgentRef::TYPE_INTERNAL );
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
	 * A valid acts_for value. An unknown or empty one falls back to the given
	 * default, which is the least trusted choice (the public) unless a caller
	 * that is creating an agent on purpose says otherwise.
	 *
	 * @param string $acts_for Stored or requested value.
	 * @param string $fallback What an unknown or empty value becomes.
	 */
	public static function normalize_acts_for( string $acts_for, string $fallback = self::ACTS_FOR_PUBLIC ): string {
		if ( in_array( $acts_for, self::acts_for_values(), true ) ) {
			return $acts_for;
		}

		return in_array( $fallback, self::acts_for_values(), true ) ? $fallback : self::ACTS_FOR_PUBLIC;
	}

	/**
	 * A short, readable label for an acts_for value.
	 *
	 * @param string $acts_for An acts_for value.
	 */
	public static function acts_for_label( string $acts_for ): string {
		$labels = array(
			self::ACTS_FOR_ADMIN   => __( 'Store administrator', 'zorem-ai-agent-connector' ),
			self::ACTS_FOR_PARTNER => __( 'A partner', 'zorem-ai-agent-connector' ),
			self::ACTS_FOR_PUBLIC  => __( 'The public', 'zorem-ai-agent-connector' ),
		);

		return $labels[ $acts_for ] ?? $acts_for;
	}

	/**
	 * How this agent authenticates.
	 */
	public function get_auth_method(): string {
		return $this->auth_method;
	}

	/**
	 * Displayable token prefix. Never the token itself.
	 */
	public function get_token_prefix(): ?string {
		return $this->token_prefix;
	}

	/**
	 * When the current token was issued.
	 */
	public function get_token_created_at(): ?string {
		return $this->token_created_at;
	}

	/**
	 * When the token was last accepted.
	 */
	public function get_token_last_used_at(): ?string {
		return $this->token_last_used_at;
	}

	/**
	 * The WP user id this agent borrows for request context.
	 */
	public function get_shadow_user_id(): int {
		return $this->shadow_user_id;
	}

	/**
	 * Expiry timestamp in UTC, or null for no expiry.
	 */
	public function get_expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * Admin who created this agent.
	 */
	public function get_created_by(): int {
		return $this->created_by;
	}

	/**
	 * Creation timestamp.
	 */
	public function get_created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last modification timestamp.
	 */
	public function get_updated_at(): string {
		return $this->updated_at;
	}

	/**
	 * All metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function get_meta(): array {
		return $this->meta;
	}

	/**
	 * One metadata value.
	 *
	 * @param string $key           Meta key.
	 * @param mixed  $default_value Returned when absent.
	 * @return mixed
	 */
	public function meta( string $key, $default_value = null ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : $default_value;
	}

	/**
	 * Whether the agent has passed its expiry.
	 *
	 * @param int|null $now Unix timestamp, for testing.
	 */
	public function is_expired( ?int $now = null ): bool {
		if ( null === $this->expires_at ) {
			return false;
		}

		$now     = $now ?? time();
		$expires = strtotime( $this->expires_at . ' UTC' );

		if ( false === $expires ) {
			// An unparseable expiry is treated as expired: fail closed.
			return true;
		}

		return $now >= $expires;
	}

	/**
	 * Whether the agent may act right now.
	 *
	 * @param int|null $now Unix timestamp, for testing.
	 */
	public function is_active( ?int $now = null ): bool {
		return self::STATUS_ACTIVE === $this->status && ! $this->is_expired( $now ) && $this->shadow_user_id > 0;
	}

	/**
	 * Why the agent cannot act, or null when it can.
	 *
	 * The vocabulary is shared with the governance decisions, so a refusal
	 * reads the same whichever side of the gate produced it.
	 *
	 * @param int|null $now Unix timestamp, for testing.
	 */
	public function inactive_reason( ?int $now = null ): ?string {
		if ( self::STATUS_REVOKED === $this->status ) {
			return 'agent_revoked';
		}

		if ( self::STATUS_ACTIVE !== $this->status ) {
			return 'agent_suspended';
		}

		if ( $this->is_expired( $now ) ) {
			return 'agent_expired';
		}

		// No account of its own to act as: it cannot act at all.
		if ( $this->shadow_user_id < 1 ) {
			return 'agent_suspended';
		}

		return null;
	}

	/**
	 * Whether destructive abilities may be granted to this agent at all.
	 *
	 * The global setting is checked separately by PermissionResolver; both must
	 * be true.
	 */
	public function allows_destructive(): bool {
		return (bool) $this->meta( 'destructive_enabled', false );
	}

	/**
	 * Whether customer personal data should be masked in responses.
	 *
	 * Defaults by who the agent acts for rather than to a single global
	 * default: an agent acting for a partner or the public starts masked; one
	 * acting for the store administrator does not.
	 */
	public function masks_pii(): bool {
		$explicit = $this->meta( 'pii_masked', null );

		if ( null !== $explicit ) {
			return (bool) $explicit;
		}

		// Anyone not acting for an administrator gets masked customer data unless told otherwise.
		return self::ACTS_FOR_ADMIN !== $this->acts_for;
	}

	/**
	 * Array form for REST responses. Never includes secrets.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'id'                  => $this->id,
			'uuid'                => $this->uuid,
			'name'                => $this->name,
			'slug'                => $this->slug,
			'status'              => $this->status,
			'acts_for'            => $this->acts_for,
			'ref'                 => $this->ref(),
			'auth_method'         => $this->auth_method,
			'token_prefix'        => $this->token_prefix,
			'token_created_at'    => $this->token_created_at,
			'token_last_used_at'  => $this->token_last_used_at,
			'shadow_user_id'      => $this->shadow_user_id,
			'expires_at'          => $this->expires_at,
			'is_expired'          => $this->is_expired(),
			'is_active'           => $this->is_active(),
			'pii_masked'          => $this->masks_pii(),
			'destructive_enabled' => $this->allows_destructive(),
			'created_at'          => $this->created_at,
			'updated_at'          => $this->updated_at,
			'meta'                => $this->meta,
		);
	}
}
