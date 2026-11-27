<?php
/**
 * Fencing for prompts.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Enrichment;

use ZoremAgentBridge\V1\Security\Fence;

defined( 'ABSPATH' ) || exit;

/**
 * The shared fence for untrusted text in a prompt.
 *
 * Left from AI enrichment, which 1.3.0 removed: the assistants' prompts
 * (AI Shopping Assistant's `ShopPromptBuilder`, `Transcript`) fence quoted
 * text through it, so every prompt on the site shares one boundary — the
 * bridge's Fence — and one way of keeping quoted text from closing it.
 */
final class PromptBuilder {

	/**
	 * The fence, exposed so tests can assert the structure.
	 */
	public static function fence(): string {
		return Fence::DELIMITER;
	}

	/**
	 * Wrap untrusted text in a delimited block — the bridge's Fence, so every
	 * prompt on the site shares one boundary and one way of keeping author
	 * text from closing it.
	 *
	 * @param string $text Untrusted text.
	 */
	public function fenced( string $text ): string {
		return Fence::wrap( $text );
	}
}
