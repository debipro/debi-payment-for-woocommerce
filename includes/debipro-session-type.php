<?php
/**
 * Default Debi session types a product can open.
 *
 * These mirror the Debi checkout "session" a product opens by default. At the
 * Debi API a session `kind` is only `payment`, `subscription` or `mandate`;
 * `installment` is a product-level convenience that maps to a `payment` session
 * with the `installments` / `max_installments` param. A product stores one of
 * these as its default; it can be changed, and a product may accept other
 * session types later.
 *
 * @package WooCommerce_Debi
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

enum DebiProSessionType: string {
	case Payment      = 'payment';
	case Installment  = 'installment';
	case Subscription = 'subscription';
	case Mandate      = 'mandate';
}
