<?php
/**
 * Curated ability to capability map for WooCommerce 11.3.
 *
 * Same method and same entries as woocommerce-11.0.php, plus the one ability
 * WooCommerce 11.3 adds. Read from WooCommerce 11.3.0's
 * src/Internal/Abilities/Domain/ with the 11.0 map's verification grep:
 *
 *   grep -rn "wc_rest_check_post_permissions\|current_user_can" \
 *     src/Internal/Abilities/Domain/*.php src/Internal/Abilities/Domain/Traits/*.php
 *
 * What changed from 11.0:
 *
 *   OrderNotesQuery    NEW. permission_callback can_view_order() →
 *                      OrderAbilityTrait::check_order_permission( $order_id, 'read' ) →
 *                      wc_rest_check_post_permissions( $type, 'read', $order_id ),
 *                      the same read check as OrdersQuery with an id.
 *   OrderAbilityTrait  check_order_permission() now checks the order's own type
 *                      (OrderUtil::get_order_type(), shop_order when unknown)
 *                      and refuses types the abilities do not support. For a
 *                      shop_order the capabilities are unchanged.
 *   ProductCreate,     now built from ActionableAbility (the execute callback
 *   ProductUpdate      is set in prepare_properties(); see
 *                      AbilityGate::adopt_deferred()). Their permission checks
 *                      are unchanged: create on product; edit on the product,
 *                      plus publish_products when publishing.
 *
 * Every other callback is unchanged from the 11.0 map.
 *
 * Verified on WooCommerce 11.3.0 by running each ability as an agent with a
 * write grant: order-notes-query was refused with read_private_shop_orders
 * withheld before this entry, the only capability its check asked for.
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

	// New in 11.3. Checked in the read context on the order, like orders-query with an id.
	'woocommerce/order-notes-query'   => array(
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
