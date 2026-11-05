<?php
/**
 * Permission decision value object.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of a permission check, with the reason attached.
 *
 * A bare boolean would lose the reason, and the reason is the product: the
 * activity log's value comes from being able to say *why* something was
 * blocked, not merely that it was.
 */
final class Decision {

	/** Reason codes, shared with the activity log's block_reason column. */
	public const NOT_GRANTED          = 'not_granted';
	public const GRANT_TOO_LOW        = 'grant_too_low';
	public const DESTRUCTIVE_DISABLED = 'destructive_disabled';
	public const AGENT_EXPIRED        = 'agent_expired';
	public const AGENT_REVOKED        = 'agent_revoked';
	public const AGENT_SUSPENDED      = 'agent_suspended';
	public const INVISIBLE_TOOL       = 'invisible_tool';
	public const WP_DENIED            = 'wp_denied';
	public const CAP_WITHHELD         = 'cap_withheld';
	public const RATE_LIMITED         = 'rate_limited';
	public const HIDDEN_ABILITY       = 'hidden_ability';
	public const WRONG_ADDRESS        = 'wrong_agent_address';
	public const UNGOVERNED           = 'ungoverned_ability';

	/**
	 * Whether the action may proceed.
	 *
	 * @var bool
	 */
	private bool $allowed;

	/**
	 * Reason code when denied.
	 *
	 * @var string|null
	 */
	private ?string $reason;

	/**
	 * The grant level that applied.
	 *
	 * @var string
	 */
	private string $grant_level;

	/**
	 * Constructor.
	 *
	 * @param bool        $allowed     Whether the action may proceed.
	 * @param string|null $reason      Reason code when denied.
	 * @param string      $grant_level The grant level that applied.
	 */
	private function __construct( bool $allowed, ?string $reason = null, string $grant_level = 'none' ) {
		$this->allowed     = $allowed;
		$this->reason      = $reason;
		$this->grant_level = $grant_level;
	}

	/**
	 * An allowing decision.
	 *
	 * @param string $grant_level The grant level that applied.
	 */
	public static function allow( string $grant_level = 'read' ): self {
		return new self( true, null, $grant_level );
	}

	/**
	 * A denying decision.
	 *
	 * @param string $reason      One of the reason codes above.
	 * @param string $grant_level The grant level that applied.
	 */
	public static function deny( string $reason, string $grant_level = 'none' ): self {
		return new self( false, $reason, $grant_level );
	}

	/**
	 * Whether the action may proceed.
	 */
	public function is_allowed(): bool {
		return $this->allowed;
	}

	/**
	 * Why the action was denied, or null.
	 */
	public function get_reason(): ?string {
		return $this->reason;
	}

	/**
	 * The grant level that applied.
	 */
	public function get_grant_level(): string {
		return $this->grant_level;
	}

	/**
	 * Every refusal reason, with its label: for the admin UI and the bridge's reason vocabulary.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			self::NOT_GRANTED          => __( 'This agent has not been granted this ability.', 'zorem-ai-agent-connector' ),
			self::GRANT_TOO_LOW        => __( 'This agent has read access, but this ability requires write access.', 'zorem-ai-agent-connector' ),
			self::DESTRUCTIVE_DISABLED => __( 'This ability is destructive and destructive abilities are not enabled for this agent.', 'zorem-ai-agent-connector' ),
			self::AGENT_EXPIRED        => __( 'This agent\'s access has expired.', 'zorem-ai-agent-connector' ),
			self::AGENT_REVOKED        => __( 'This agent has been revoked.', 'zorem-ai-agent-connector' ),
			self::AGENT_SUSPENDED      => __( 'This agent is not active.', 'zorem-ai-agent-connector' ),
			self::INVISIBLE_TOOL       => __( 'This agent cannot see this tool.', 'zorem-ai-agent-connector' ),
			self::WP_DENIED            => __( 'WordPress denied this action for the agent\'s user context.', 'zorem-ai-agent-connector' ),
			self::CAP_WITHHELD         => __( 'This ability needs a WordPress capability that is never lent automatically, because it would allow changing users, plugins, site settings, other people’s content or site code.', 'zorem-ai-agent-connector' ),
			self::RATE_LIMITED         => __( 'This agent has exceeded its request rate limit.', 'zorem-ai-agent-connector' ),
			self::HIDDEN_ABILITY       => __( 'This ability is withheld on this site and cannot be granted.', 'zorem-ai-agent-connector' ),
			self::WRONG_ADDRESS        => __( 'This credential was presented at an MCP address that does not belong to it.', 'zorem-ai-agent-connector' ),
			self::UNGOVERNED           => __( 'This ability was registered in a way this plugin cannot oversee, so the agent may not use it. Diagnostics names the cause.', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * A translated, human-readable explanation for the admin UI.
	 */
	public function get_message(): string {
		if ( $this->allowed ) {
			return __( 'Allowed.', 'zorem-ai-agent-connector' );
		}

		return self::labels()[ (string) $this->reason ] ?? __( 'Denied.', 'zorem-ai-agent-connector' );
	}
}
