<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Projection;

/**
 * Order meta keys and origin / payment-status values for the Debi projection.
 */
final class OrderMeta {

	public const SUBSCRIPTION_ID     = '_debipro_subscription_id';
	public const PAYMENT_ID          = '_debipro_payment_id';
	public const CUSTOMER_ID         = '_debipro_customer_id';
	public const FINAL_PRICE         = '_debipro_final_price';
	public const ORIGIN              = '_debipro_origin';
	public const PAYMENT_STATUS      = '_debipro_payment_status';
	public const SUBSCRIPTION_STATUS = '_debipro_subscription_status';
	public const AMOUNT_PAID         = '_debipro_amount_paid';
	public const AMOUNT_OVERDUE      = '_debipro_amount_overdue';
	public const AMOUNT_REMAINING    = '_debipro_amount_remaining';
	public const AMOUNT_EXTERNAL     = '_debipro_amount_external';
	public const PAYMENTS_SYNCED_AT  = '_debipro_payments_synced_at';
	public const PROCESSED_EVENTS    = '_debipro_processed_events';

	public const ORIGIN_INSTALLMENT_PLAN          = 'installment_plan';
	public const ORIGIN_DEBI_SUBSCRIPTION_PAYMENT = 'debi_subscription_payment';
	public const ORIGIN_DEBI_ONE_OFF_PAYMENT      = 'debi_one_off_payment';

	public const STATUS_CURRENT   = 'current';
	public const STATUS_PAST_DUE  = 'past_due';
	public const STATUS_PAID      = 'paid';
	public const STATUS_CANCELLED = 'cancelled';

	/**
	 * Whether an order is a checkout-financed plan (Phase 1).
	 *
	 * Missing origin is treated as installment_plan for orders that already
	 * carried a subscription id before this meta existed.
	 */
	public static function is_checkout_plan( \WC_Order $order ): bool {
		$origin = (string) $order->get_meta( self::ORIGIN );
		if ( '' === $origin ) {
			return '' !== (string) $order->get_meta( self::SUBSCRIPTION_ID );
		}
		return self::ORIGIN_INSTALLMENT_PLAN === $origin;
	}
}
