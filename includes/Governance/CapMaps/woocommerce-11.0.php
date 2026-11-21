<?php
/**
 * Curated ability to capability map for WooCommerce 11.0. Verified back to 10.9.
 *
 * Derived by reading WooCommerce's own ability permission callbacks in
 * src/Internal/Abilities/Domain/. Each of them delegates to
 * wc_rest_check_post_permissions( $post_type, $context, $id ), which resolves
 * the post type's capability for that context and calls current_user_can().
 * The primitive capabilities listed here are what those checks ultimately
 * require once map_meta_cap() has run.
 *
 * VERIFICATION METHOD (repeat this for every re-key; do not guess):
 * grep the permission callbacks across all seven ability classes and their
 * traits —
 *
 *   grep -rn "wc_rest_check_post_permissions\|current_user_can" \
 *     src/Internal/Abilities/Domain/*.php src/Internal/Abilities/Domain/Traits/*.php
 *
 * — in both WooCommerce versions, and diff the results. For 10.9.3 vs 11.0.1
 * the output was identical on all seven abilities:
 *
 *   OrdersQuery        wc_rest_check_post_permissions( 'shop_order', 'read', $order_id )
 *   OrderAbilityTrait  wc_rest_check_post_permissions( 'shop_order', 'edit', $order_id )
 *                      (backs OrderAddNote and OrderUpdateStatus)
 *   ProductsQuery      wc_rest_check_post_permissions( 'product', 'read', $product_id )
 *   ProductCreate      wc_rest_check_post_permissions( 'product', 'create' )
 *   ProductUpdate      wc_rest_check_post_permissions( 'product', 'edit', $product_id )
 *                      + current_user_can( 'publish_products' ) when publishing a draft
 *   ProductDelete      wc_rest_check_post_permissions( 'product', 'delete', $product_id )
 *
 * Conclusion: one map is valid for the 10.9 and 11.0 series. A WooCommerce
 * older than every shipped map falls back to this file (see CapMapLoader),
 * which is correct for 10.9 by the diff above.
 *
 * When a future WC version changes any of these callbacks, add a new map file
 * keyed to that version rather than editing this one, so sites on older
 * WooCommerce keep the map that matches their code.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

return array(
	// Checked in the read context on the shop_order post type, which resolves to read_private_posts.
	'woocommerce/orders-query'        => array(
		'read_private_shop_orders',
	),

	/*
	 * Checked in the edit context on shop_order, which resolves through the
	 * edit_post meta capability. (AI Agent OS PRO also lends publish_shop_orders
	 * here, for the WooCommerce REST notes route its API door maps to this
	 * ability; this plugin has no such door, so it is not lent.)
	 */
	'woocommerce/order-add-note'      => array(
		'read_private_shop_orders',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'edit_published_shop_orders',
	),

	'woocommerce/order-update-status' => array(
		'read_private_shop_orders',
		'edit_shop_orders',
		'edit_others_shop_orders',
		'edit_published_shop_orders',
	),

	// Checked in the read context on the product post type, which resolves to read_private_posts.
	'woocommerce/products-query'      => array(
		'read_private_products',
	),

	// Checked in the create context on product, which resolves to publish_posts.
	'woocommerce/product-create'      => array(
		'publish_products',
		'edit_products',
	),

	/*
	 * ProductUpdate additionally calls current_user_can( 'publish_products' )
	 * directly when the update would publish a draft.
	 */
	'woocommerce/product-update'      => array(
		'read_private_products',
		'edit_products',
		'edit_others_products',
		'edit_published_products',
		'publish_products',
	),

	// Checked in the delete context on product, which resolves through the delete_post meta capability.
	'woocommerce/product-delete'      => array(
		'read_private_products',
		'delete_products',
		'delete_others_products',
		'delete_published_products',
	),
);
