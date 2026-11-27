---
entry_key: plugin:woocommerce:abilities
section: abilities
title: WooCommerce order and product abilities
summary: The seven abilities WooCommerce registers, what each one is for, and which grant level it needs.
plugin_min_version: "10.9"
plugin_max_version: "11.99"
---

WooCommerce registers seven abilities: three for orders, four for products.
Extraction can list them and read their schemas; what follows is the part it
cannot infer — when to reach for each one, and where the sharp edges are.

## Orders

`woocommerce/orders-query` is the one to start from. It finds orders by ID or by
the filters a shop manager would use — status, customer, date range. It reads
only, so a `read` grant is enough, and it is the right way to answer "what is
happening with this order" before doing anything else.

`woocommerce/order-add-note` appends a note. Notes are either private (visible
to shop staff in the admin) or customer-facing, and a customer-facing note
**sends an email**. Treat it as an outward-facing action even though it is
technically a write to an order record.

`woocommerce/order-update-status` moves an order through the status workflow.
This is where fulfilment work actually happens: `processing` → `completed` is
the common transition. It carries WooCommerce's `destructive` annotation, which
in MCP's vocabulary means "not merely additive" rather than "irreversible" — a
status change can be changed back. A `write` grant covers it.

Status transitions trigger emails. Moving an order to `completed` sends the
customer their completion email, and moving it to `refunded` does not perform a
refund — it records that one happened. Refunding money is not something these
abilities do.

## Products

`woocommerce/products-query` reads the catalogue. Like the order query, it needs
only a `read` grant and is the right first call.

`woocommerce/product-create` and `woocommerce/product-update` write catalogue
fields. Both are ordinary writes despite the `destructive` annotation, for the
same reason as the status update: the annotation marks non-additive operations,
not irreversible ones.

`woocommerce/product-delete` is the one to be careful with. It can trash,
restore, or permanently delete. Trashing is reversible; permanent deletion is
not, and it removes the product from order history views that reference it.

## Facts

```json
{
  "abilities": [
    { "name": "woocommerce/orders-query", "grant_needed": "read" },
    { "name": "woocommerce/order-add-note", "grant_needed": "write", "sends_email": true },
    { "name": "woocommerce/order-update-status", "grant_needed": "write", "sends_email": true },
    { "name": "woocommerce/products-query", "grant_needed": "read" },
    { "name": "woocommerce/product-create", "grant_needed": "write" },
    { "name": "woocommerce/product-update", "grant_needed": "write" },
    { "name": "woocommerce/product-delete", "grant_needed": "write", "irreversible_mode": "force" }
  ]
}
```
