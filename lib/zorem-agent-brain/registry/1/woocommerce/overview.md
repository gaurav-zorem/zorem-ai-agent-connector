---
entry_key: plugin:woocommerce:overview
section: overview
title: WooCommerce
summary: An open-source ecommerce platform for WordPress. Orders, products, customers and the statuses that connect them.
plugin_min_version: "10.9"
plugin_max_version: "11.99"
---

WooCommerce turns a WordPress site into a shop. Almost everything an agent will
be asked about maps onto three things: **products** (what is for sale),
**orders** (what someone bought), and the **statuses** that track an order from
payment to delivery.

An order's status is the single most useful field. The ones that matter in
practice:

- `pending` — created, not paid.
- `processing` — paid, needs fulfilling. This is the queue that matters.
- `on-hold` — awaiting payment confirmation, or held by staff.
- `completed` — fulfilled. Setting this emails the customer.
- `cancelled`, `refunded`, `failed` — terminal, and each means something
  specific. `refunded` records that a refund happened; it does not perform one.

Orders are stored in dedicated tables rather than as posts on modern
installations. That matters if you are reasoning about performance or about what
a generic WordPress tool can see: a post-based query will not find them.

Products come in types. A **simple** product is one item at one price. A
**variable** product has variations, each with its own price and stock, and the
parent carries the shared attributes — asking about the price of a variable
product is usually a question about its variations. **Grouped** and **external**
products exist too and are rarer.

Stock is tracked either at the product level or per variation, and can be
switched off entirely, in which case a product is always purchasable. "Out of
stock" and "not tracking stock" look similar from the outside and mean very
different things.
