# Debi Payment for WooCommerce

WooCommerce gateway that collects card payments through Debi’s subscription API and mirrors Debi money state onto WooCommerce orders.

## Language

**Checkout order**:
A WooCommerce order created at store checkout that finances its total via a Debi subscription (installment plan or single charge modelled as `count=1`).
_Avoid_: parent order, WC subscription

**Inbound order**:
A WooCommerce order created from a Debi payment that was not born at checkout (donation, Debi-native recurring charge, one-off Debi payment).
_Avoid_: orphan order, ghost order

**Payment projection**:
Summarized money fields stored on a WooCommerce order (`amount_paid`, `amount_overdue`, `amount_remaining`, `payment_status`) recomputed from Debi’s current payment list. Debi remains the ledger.
_Avoid_: ledger, balance, running total

**Payment status** (order meta `_debipro_payment_status`):
Merchant-facing health of the plan or charge: `current`, `past_due`, `paid`, or `cancelled`. Distinct from WooCommerce order status.
_Avoid_: Debi payment status, rejected (as an order-level label)

**Origin** (`_debipro_origin`):
How the Debi↔order link was born: `installment_plan` (checkout), `debi_subscription_payment`, or `debi_one_off_payment`.
_Avoid_: type, kind, source (unqualified)

**External settlement**:
An admin-recorded payment outside Debi that increases paid amount on a (possibly cancelled) checkout order so it can reach `completed`.
_Avoid_: manual capture, offline payment (unqualified)
