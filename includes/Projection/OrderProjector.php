<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Projection;

/**
 * Writes payment projection meta onto a WooCommerce order and optionally moves WC status.
 */
final class OrderProjector {

	/**
	 * @param list<array{amount?: float|int|string, status?: string}> $payments
	 * @return string Short result code.
	 */
	public static function apply(
		\WC_Order $order,
		string $subscription_status,
		array $payments,
		string $note = ''
	): string {
		$is_checkout = OrderMeta::is_checkout_plan( $order );
		$target      = self::target_amount( $order );
		$external    = PaymentAmounts::money( (float) $order->get_meta( OrderMeta::AMOUNT_EXTERNAL ) );

		$sums   = PaymentAmounts::summarize( $payments );
		$merged = PaymentAmounts::with_external(
			$sums['amount_paid'],
			$sums['amount_overdue'],
			$external,
			$target
		);

		$order->update_meta_data( OrderMeta::AMOUNT_PAID, (string) $merged['amount_paid'] );
		$order->update_meta_data( OrderMeta::AMOUNT_OVERDUE, (string) $merged['amount_overdue'] );
		$order->update_meta_data( OrderMeta::AMOUNT_REMAINING, (string) $merged['amount_remaining'] );
		$order->update_meta_data( OrderMeta::SUBSCRIPTION_STATUS, $subscription_status );
		$order->update_meta_data(
			OrderMeta::PAYMENTS_SYNCED_AT,
			gmdate( 'c' )
		);

		$rules = $is_checkout
			? StatusRules::for_checkout_plan(
				$subscription_status,
				$merged['amount_paid'],
				$merged['amount_overdue'],
				$target
			)
			: StatusRules::for_inbound_payment(
				$merged['amount_paid'],
				$merged['amount_overdue'],
				$target
			);

		$order->update_meta_data( OrderMeta::PAYMENT_STATUS, $rules['payment_status'] );

		$wc_status = $rules['wc_status'];
		$result    = 'projected_' . $rules['payment_status'];

		if ( null !== $wc_status && ! $order->has_status( $wc_status ) ) {
			$order->update_status(
				$wc_status,
				'' !== $note ? $note : sprintf(
					'Debi payment projection → %s (%s). ',
					$wc_status,
					$rules['payment_status']
				)
			);
			$result = 'updated_' . $wc_status;
		}

		$order->save();
		return $result;
	}

	public static function target_amount( \WC_Order $order ): float {
		$final = $order->get_meta( OrderMeta::FINAL_PRICE );
		if ( '' !== $final && null !== $final ) {
			return PaymentAmounts::money( (float) $final );
		}
		if ( method_exists( $order, 'get_total' ) ) {
			return PaymentAmounts::money( (float) $order->get_total() );
		}
		return 0.0;
	}

	/**
	 * Record an outside-Debi payment (cash/transfer). May complete a cancelled order.
	 */
	public static function record_external_payment( \WC_Order $order, float $amount, string $note = '' ): string {
		$amount = PaymentAmounts::money( $amount );
		if ( $amount <= 0 ) {
			return 'invalid_amount';
		}

		$current = PaymentAmounts::money( (float) $order->get_meta( OrderMeta::AMOUNT_EXTERNAL ) );
		$order->update_meta_data( OrderMeta::AMOUNT_EXTERNAL, (string) ( $current + $amount ) );
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				'' !== $note
					? $note
					: sprintf( 'External Debi settlement recorded: %s.', (string) $amount )
			);
		}

		// Re-apply with empty Debi payment list would wipe Debi sums — caller should
		// pass through OrderSync::reproject_order. Here we only bump external and
		// recompute from stored Debi-ish fields if present is wrong. Keep note + meta;
		// return code that signals a full reproject is needed.
		$order->save();
		return 'external_recorded';
	}
}
