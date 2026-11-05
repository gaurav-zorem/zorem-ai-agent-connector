<?php
/**
 * The event catalogue.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Every event the bridge carries, and the payload keys each must have.
 *
 * A closed list, so an event name cannot be misspelled into a new event that
 * nothing listens to. Events are notifications of facts that already
 * happened. Nothing that decides access may read one (PATTERNS 9).
 */
final class Events {

	public const AGENT_CREATED          = 'agent.created';
	public const AGENT_REVOKED          = 'agent.revoked';
	public const AGENT_EXPIRED          = 'agent.expired';
	public const AGENT_TOKEN_ROTATED    = 'agent.token_rotated';
	public const PERMISSIONS_CHANGED    = 'permissions.changed';
	public const SKILL_APPROVED         = 'skill.approved';
	public const SKILL_REJECTED         = 'skill.rejected';
	public const CONVERSATION_STARTED   = 'conversation.started';
	public const CONVERSATION_ANSWERED  = 'conversation.answered';
	public const CART_CREATED           = 'cart.created';
	public const CHECKOUT_HANDOFF       = 'checkout.handoff';
	public const ORDER_ATTRIBUTED       = 'order.attributed';
	public const HANDOFF_REQUESTED      = 'handoff.requested';
	public const HANDOFF_COMPLETED      = 'handoff.completed';
	public const BRIDGE_CONFLICT        = 'bridge.conflict';
	public const BRAIN_ENTRY_UPDATED    = 'brain.entry_updated';
	public const BRAIN_SOURCE_COMPLETED = 'brain.source_completed';
	public const BRAIN_COLLECTION_EDIT  = 'brain.collection_changed';
	public const BRAIN_HOST_JOINED      = 'brain.host_joined';

	/**
	 * Required payload keys per event.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function schemas(): array {
		return array(
			self::AGENT_CREATED          => array( 'agent_ref' ),
			self::AGENT_REVOKED          => array( 'agent_ref' ),
			self::AGENT_EXPIRED          => array( 'agent_ref' ),
			self::AGENT_TOKEN_ROTATED    => array( 'agent_ref' ),
			self::PERMISSIONS_CHANGED    => array( 'agent_ref' ),
			self::SKILL_APPROVED         => array( 'skill_slug' ),
			self::SKILL_REJECTED         => array( 'skill_slug' ),
			self::CONVERSATION_STARTED   => array( 'conversation_ref', 'experience' ),
			self::CONVERSATION_ANSWERED  => array( 'conversation_ref', 'experience', 'grounded' ),
			self::CART_CREATED           => array( 'conversation_ref' ),
			self::CHECKOUT_HANDOFF       => array( 'conversation_ref' ),
			self::ORDER_ATTRIBUTED       => array( 'conversation_ref', 'order_id' ),
			self::HANDOFF_REQUESTED      => array( 'from', 'to', 'reason' ),
			self::HANDOFF_COMPLETED      => array( 'handoff_id' ),
			self::BRIDGE_CONFLICT        => array( 'contract', 'owners' ),
			self::BRAIN_ENTRY_UPDATED    => array( 'entry_key' ),
			self::BRAIN_SOURCE_COMPLETED => array( 'source_id', 'complete' ),
			self::BRAIN_COLLECTION_EDIT  => array( 'collection' ),
			self::BRAIN_HOST_JOINED      => array( 'host' ),
		);
	}

	/**
	 * The WordPress action an event is delivered on.
	 *
	 * Underscores only, so hook names pass WordPress's naming rules:
	 * "cart.created" is delivered on `zorem_agent_bridge_v1_cart_created`.
	 *
	 * @param string $name Event name.
	 */
	public static function hook( string $name ): string {
		return 'zorem_agent_bridge_v1_' . str_replace( '.', '_', $name );
	}
}
