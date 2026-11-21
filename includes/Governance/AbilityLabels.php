<?php
/**
 * Friendly names for abilities and sections this plugin does not register.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Governance;

defined( 'ABSPATH' ) || exit;

/**
 * Names a store owner understands, for abilities and sections registered by
 * WordPress core and other plugins (WooCommerce, Returns, the Website Brain).
 *
 * This plugin's own abilities carry friendly labels where they are
 * registered; these cannot, so the Permissions screen and the Activity Log
 * show the name from this list instead of the registering plugin's label
 * ("Query orders" becomes "See orders"). Only the label shown here changes:
 * the ability, its name and what it does are the other plugin's.
 */
final class AbilityLabels {

	/**
	 * Ability name => friendly label.
	 *
	 * @return array<string, string>
	 */
	public static function abilities(): array {
		return array(
			'core/get-environment-info'                  => __( 'See server and WordPress details', 'zorem-ai-agent-connector' ),
			'core/get-site-info'                         => __( 'See site name and address', 'zorem-ai-agent-connector' ),
			'core/get-user-info'                         => __( 'See who is signed in', 'zorem-ai-agent-connector' ),
			'woocommerce/orders-query'                   => __( 'See orders', 'zorem-ai-agent-connector' ),
			'woocommerce/order-notes-query'              => __( 'See order notes', 'zorem-ai-agent-connector' ),
			'woocommerce/order-add-note'                 => __( 'Add a note to an order', 'zorem-ai-agent-connector' ),
			'woocommerce/order-update-status'            => __( 'Change an order’s status', 'zorem-ai-agent-connector' ),
			'woocommerce/products-query'                 => __( 'See products', 'zorem-ai-agent-connector' ),
			'woocommerce/product-create'                 => __( 'Add a product', 'zorem-ai-agent-connector' ),
			'woocommerce/product-update'                 => __( 'Edit a product', 'zorem-ai-agent-connector' ),
			'woocommerce/product-delete'                 => __( 'Delete a product', 'zorem-ai-agent-connector' ),
			'zorem-returns/admin-list-return-requests'   => __( 'See return requests', 'zorem-ai-agent-connector' ),
			'zorem-returns/admin-get-return-request'     => __( 'Open a return request', 'zorem-ai-agent-connector' ),
			'zorem-returns/admin-update-return-status'   => __( 'Change a return’s status', 'zorem-ai-agent-connector' ),
			'zorem-returns/admin-list-exchange-requests' => __( 'See exchange requests', 'zorem-ai-agent-connector' ),
			'zorem-returns/admin-update-exchange-status' => __( 'Change an exchange’s status', 'zorem-ai-agent-connector' ),
			'zorem-returns/create-return-request'        => __( 'Start a return for a customer', 'zorem-ai-agent-connector' ),
			'zorem-returns/create-exchange-request'      => __( 'Start an exchange for a customer', 'zorem-ai-agent-connector' ),
			'zorem-returns/list-eligible-orders'         => __( 'See orders that can be returned', 'zorem-ai-agent-connector' ),
			'zorem-returns/get-order-items'              => __( 'See items in an order', 'zorem-ai-agent-connector' ),
			'zorem-returns/get-return-reasons'           => __( 'See return reasons', 'zorem-ai-agent-connector' ),
			'zorem-returns/list-my-returns'              => __( 'See a customer’s own returns', 'zorem-ai-agent-connector' ),
			'zorem-returns/search-products'              => __( 'Search products', 'zorem-ai-agent-connector' ),
			'agent-brain/knowledge-search'               => __( 'Search store knowledge', 'zorem-ai-agent-connector' ),
			'agent-brain/knowledge-list-topics'          => __( 'See knowledge topics', 'zorem-ai-agent-connector' ),
			'agent-brain/knowledge-get-entry'            => __( 'Open a knowledge article', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * Section (ability category) slug => friendly label.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'site'        => __( 'Site information', 'zorem-ai-agent-connector' ),
			'user'        => __( 'Your account', 'zorem-ai-agent-connector' ),
			'ai-agent-os' => __( 'About this agent', 'zorem-ai-agent-connector' ),
		);
	}

	/**
	 * The label to show for an ability.
	 *
	 * @param string $name     Ability name, e.g. "woocommerce/orders-query".
	 * @param string $fallback The registered label.
	 */
	public static function ability( string $name, string $fallback ): string {
		return self::abilities()[ $name ] ?? $fallback;
	}

	/**
	 * The label to show for a section.
	 *
	 * @param string $slug     Category slug.
	 * @param string $fallback The registered label.
	 */
	public static function category( string $slug, string $fallback ): string {
		return self::categories()[ $slug ] ?? $fallback;
	}
}
