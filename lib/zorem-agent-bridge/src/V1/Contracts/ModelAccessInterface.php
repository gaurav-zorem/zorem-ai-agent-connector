<?php
/**
 * Contract: calling a model.
 *
 * @package ZoremAgentBridge
 */

declare( strict_types=1 );

namespace ZoremAgentBridge\V1\Contracts;

use ZoremAgentBridge\V1\Dto\ModelLease;

defined( 'ABSPATH' ) || exit;

/**
 * Whether the site can and may call a model. Provided by the Business Brain Kit,
 * which holds the site's one AI key.
 */
interface ModelAccessInterface {

	/** Audiences, which decide the wording of a refusal. */
	public const AUDIENCE_ADMIN   = 'admin';
	public const AUDIENCE_SHOPPER = 'shopper';

	/**
	 * Ask for a model for a purpose.
	 *
	 * @param string $purpose  What the call is for, e.g. "storefront", "admin_chat", "enrichment".
	 * @param string $audience One of the AUDIENCE_* constants.
	 */
	public function lease( string $purpose, string $audience ): ModelLease;
}
