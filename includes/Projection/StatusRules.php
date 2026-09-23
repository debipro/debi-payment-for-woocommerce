<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Projection;

/**
 * Derives merchant payment_status + target WooCommerce status from projection inputs.
 */
final class StatusRules {

	/**
	 * Checkout (installment) plan rules.
	 *
	 * @return array{payment_status: string, wc_status: string|null}
	 *         wc_status null means leave WooCommerce status unchanged.
	 */
	public static function for_checkout_plan(
		string $subscription_status,
		float $amount_paid,
		float $amount_overdue,
		float $target
	): array {
		$sub        = strtolower( trim( $subscription_status ) );
		$fully_paid = $target > 0 && $amount_paid + 0.001 >= $target;

		// Double gate: Debi finished + money covered. Also allow completing a
		// cancelled plan once external settlement (or late approvals) cover the target.
		if ( $fully_paid && ( 'finished' === $sub || 'cancelled' === $sub ) ) {
			return array(
				'payment_status' => OrderMeta::STATUS_PAID,
				'wc_status'      => 'completed',
			);
		}

		if ( 'cancelled' === $sub ) {
			return array(
				'payment_status' => OrderMeta::STATUS_CANCELLED,
				'wc_status'      => 'cancelled',
			);
		}

		if ( $amount_overdue > 0 ) {
			return array(
				'payment_status' => OrderMeta::STATUS_PAST_DUE,
				'wc_status'      => 'processing',
			);
		}

		return array(
			'payment_status' => OrderMeta::STATUS_CURRENT,
			'wc_status'      => 'processing',
		);
	}

	/**
	 * Inbound (per-payment) order rules. Never WC-cancels from payment/sub cancel.
	 *
	 * @return array{payment_status: string, wc_status: string|null}
	 */
	public static function for_inbound_payment(
		float $amount_paid,
		float $amount_overdue,
		float $target
	): array {
		if ( $amount_paid + 0.001 >= $target && $target > 0 ) {
			return array(
				'payment_status' => OrderMeta::STATUS_PAID,
				'wc_status'      => 'completed',
			);
		}

		if ( $amount_overdue > 0 ) {
			return array(
				'payment_status' => OrderMeta::STATUS_PAST_DUE,
				'wc_status'      => 'processing',
			);
		}

		return array(
			'payment_status' => OrderMeta::STATUS_CURRENT,
			'wc_status'      => 'processing',
		);
	}
}
