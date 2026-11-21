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
	public const WRONG_ADDRESS        = 'replaced_address';
	public const UNGOVERNED           = 'ungoverned_ability';

	/** Why a request reached the MCP address with no usable credential. */
	public const NO_CREDENTIALS         = 'no_credentials';
	public const INVALID_TOKEN          = 'invalid_token';
	public const TOKEN_EXPIRED          = 'token_expired';
	public const TOKEN_ADDRESS_MISMATCH = 'token_address_mismatch';

	/** Why an app's sign-in (OAuth) was turned away before any agent was involved. */
	public const OAUTH_REGISTRATION_OFF     = 'oauth_registration_off';
	public const OAUTH_REGISTRATION_HOST    = 'oauth_registration_host';
	public const OAUTH_REGISTRATION_INVALID = 'oauth_registration_invalid';
	public const OAUTH_UNKNOWN_CLIENT       = 'oauth_unknown_client';
	public const OAUTH_INVALID_REQUEST      = 'oauth_invalid_request';

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
			self::NOT_GRANTED            => __( 'This agent has not been granted this ability.', 'zorem-ai-agent-connector' ),
			self::GRANT_TOO_LOW          => __( 'This agent has read access, but this ability requires write access.', 'zorem-ai-agent-connector' ),
			self::DESTRUCTIVE_DISABLED   => __( 'This ability is destructive and destructive abilities are not enabled for this agent.', 'zorem-ai-agent-connector' ),
			self::AGENT_EXPIRED          => __( 'This agent\'s access has expired.', 'zorem-ai-agent-connector' ),
			self::AGENT_REVOKED          => __( 'This agent has been revoked.', 'zorem-ai-agent-connector' ),
			self::AGENT_SUSPENDED        => __( 'This agent is not active.', 'zorem-ai-agent-connector' ),
			self::INVISIBLE_TOOL         => __( 'This agent cannot see this tool.', 'zorem-ai-agent-connector' ),
			self::WP_DENIED              => __( 'WordPress denied this action for the agent\'s user context.', 'zorem-ai-agent-connector' ),
			self::CAP_WITHHELD           => __( 'This ability needs a WordPress capability that is never lent automatically, because it would allow changing users, plugins, site settings, other people’s content or site code.', 'zorem-ai-agent-connector' ),
			self::RATE_LIMITED           => __( 'This agent has exceeded its request rate limit.', 'zorem-ai-agent-connector' ),
			self::HIDDEN_ABILITY         => __( 'This ability is withheld on this site and cannot be granted.', 'zorem-ai-agent-connector' ),
			self::WRONG_ADDRESS          => __( 'This credential was presented at an MCP address that has been replaced.', 'zorem-ai-agent-connector' ),
			self::UNGOVERNED             => __( 'This ability was registered in a way this plugin cannot oversee, so the agent may not use it. Diagnostics names the cause.', 'zorem-ai-agent-connector' ),
			self::NO_CREDENTIALS         => __( 'The request reached WordPress with no credentials. If the app did send a token, this web server removed the Authorization header before WordPress could read it: Status → Diagnostics checks this and names the fix.', 'zorem-ai-agent-connector' ),
			self::INVALID_TOKEN          => __( 'The token was not recognised: it is unknown, or it was replaced or revoked.', 'zorem-ai-agent-connector' ),
			self::TOKEN_EXPIRED          => __( 'The sign-in token has expired. The app is expected to refresh it; if it keeps failing, connect the app again.', 'zorem-ai-agent-connector' ),
			self::TOKEN_ADDRESS_MISMATCH => __( 'The token was issued for a different MCP address than the one called: the address was replaced, or this site’s address (www, http or https) changed after the app connected. Connect the app again.', 'zorem-ai-agent-connector' ),
			self::OAUTH_REGISTRATION_OFF     => __( 'An app tried to register so it could sign in, but “Let Grok sign in” is switched off in Settings.', 'zorem-ai-agent-connector' ),
			self::OAUTH_REGISTRATION_HOST    => __( 'An app tried to register so it could sign in, but its sign-in callback is not on an address this site accepts. Only Grok (grok.com, x.ai) may register; the callback it asked for is in the details.', 'zorem-ai-agent-connector' ),
			self::OAUTH_REGISTRATION_INVALID => __( 'An app tried to register so it could sign in, but its registration was incomplete or asked for something this site does not support. The details say what.', 'zorem-ai-agent-connector' ),
			self::OAUTH_UNKNOWN_CLIENT       => __( 'An app tried to sign in, but this site does not know it: it neither has a client metadata document nor registered here. The details say what it sent.', 'zorem-ai-agent-connector' ),
			self::OAUTH_INVALID_REQUEST      => __( 'An app’s sign-in request was turned away before the approval screen. The details say which check it failed.', 'zorem-ai-agent-connector' ),
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
