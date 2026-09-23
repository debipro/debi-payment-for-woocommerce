---
status: accepted
---

# Payment projection from Debi payment webhooks

Debi is the money ledger; WooCommerce holds a summarized **payment projection** on orders so merchants can filter debt and customers can see amounts owed without inventing a second ledger.

## Decision

1. **Subscribe to `payment.*` only** (`payment.created`, `payment.updated`, `payment.retrying`, `payment.cancelled`). Do not drive order state from `subscription.*` webhook deliveries. On each payment event, **retrieve the subscription** (when present) and **list its payments**, then rewrite projection meta — never incremental `+=` / `-=`.
2. **Two shapes, one router:**
   - **Checkout order** (`_debipro_origin=installment_plan`): one WC order per Debi subscription; projection accumulates across that subscription’s payments.
   - **Inbound order** (`debi_subscription_payment` / `debi_one_off_payment`): one WC order per Debi payment when no checkout order owns that subscription; created on `payment.created` as `processing`, completed when that payment is approved.
3. **Amounts:** `amount_paid` = Σ approved (+ external settlement); `amount_overdue` = Σ rejected **or** cancelled payments (single bucket); `amount_remaining` = target − paid (stored on checkout orders). Future installments that never became Payment rows stay in remaining only.
4. **WC status (checkout):** stay `processing` while the Debi subscription is active (even if `past_due`); `completed` only when Debi subscription is `finished` **and** `amount_paid >=` target; `cancelled` when Debi subscription is `cancelled`. A single payment cancelled/rejected never WC-cancels the checkout order.
5. **External settlement** may reopen/complete a WC-cancelled checkout order when the merchant records cash/transfer covering the remainder.
6. **Inbound products:** one reusable virtual product “Debi Payment”; line totals overridden per payment. No WooCommerce Subscriptions plugin.

## Considered options

- **WP as ledger with per-event deltas** — rejected; drifts on missed/out-of-order webhooks.
- **Keep `subscription.*` webhooks for terminal WC status** — rejected; payment events plus a live subscription retrieve are enough and avoid dual sources.
- **One WC order per Debi-native subscription** — rejected for inbound recurring; each charge is its own order.
- **WC-cancel when a payment is cancelled** — rejected; only subscription cancel cancels a checkout order.

## Consequences

- Existing Debi webhook endpoints must have `enabled_events` updated (installer updates in place).
- Any integration that creates Debi subscriptions outside this plugin’s checkout (e.g. a headless API) should set `_debipro_origin=installment_plan` on the WooCommerce order when it stores `_debipro_subscription_id`, so payment webhooks update that order instead of creating an inbound one.
- Reconcile CLI is mandatory safety net when payment webhooks are missed.
