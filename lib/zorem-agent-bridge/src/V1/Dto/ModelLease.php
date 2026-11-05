<?php
/**
 * Permission to call a model, or why not.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Dto;

defined( 'ABSPATH' ) || exit;

/**
 * The single answer to "can this site call a model for this purpose, and may it?".
 *
 * States are a closed set. Each carries two sentences, because the fix a
 * store owner needs to read ("your salts changed, enter the key again") is not
 * something to tell a shopper.
 */
final class ModelLease {

	/** States. */
	public const OK             = 'ok';
	public const NOT_CONFIGURED = 'not_configured';
	public const CONSENT_STALE  = 'consent_stale';
	public const SALT_ROTATED   = 'salt_rotated';
	public const UNAVAILABLE    = 'unavailable';

	/**
	 * One of the state constants.
	 *
	 * @var string
	 */
	private string $state;

	/**
	 * Provider id when OK, e.g. "anthropic".
	 *
	 * @var string
	 */
	private string $provider;

	/**
	 * Wording for a store administrator.
	 *
	 * @var string
	 */
	private string $admin_message;

	/**
	 * Wording for a shopper.
	 *
	 * @var string
	 */
	private string $shopper_message;

	/**
	 * Constructor.
	 *
	 * @param string $state           One of the state constants.
	 * @param string $provider        Provider id.
	 * @param string $admin_message   Wording for an administrator.
	 * @param string $shopper_message Wording for a shopper.
	 * @throws \InvalidArgumentException When the state is unknown.
	 */
	public function __construct( string $state, string $provider = '', string $admin_message = '', string $shopper_message = '' ) {
		if ( ! in_array( $state, array( self::OK, self::NOT_CONFIGURED, self::CONSENT_STALE, self::SALT_ROTATED, self::UNAVAILABLE ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown model lease state.' );
		}

		$this->state           = $state;
		$this->provider        = $provider;
		$this->admin_message   = $admin_message;
		$this->shopper_message = $shopper_message;
	}

	/**
	 * Whether a model may be called.
	 */
	public function is_ok(): bool {
		return self::OK === $this->state;
	}

	/**
	 * The state.
	 */
	public function state(): string {
		return $this->state;
	}

	/**
	 * Provider id.
	 */
	public function provider(): string {
		return $this->provider;
	}

	/**
	 * Wording for an administrator.
	 */
	public function admin_message(): string {
		return $this->admin_message;
	}

	/**
	 * Wording for a shopper.
	 */
	public function shopper_message(): string {
		return $this->shopper_message;
	}
}
