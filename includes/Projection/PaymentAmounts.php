<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Projection;

/**
 * Pure recompute of paid / overdue totals from Debi payment statuses.
 *
 * Unit-testable without WordPress or HTTP.
 */
final class PaymentAmounts {

	/** Debi payment statuses that count toward amount_paid. */
	private const PAID_STATUSES = array( 'approved' );

	/** Debi payment statuses that count toward amount_overdue (single bucket). */
	private const OVERDUE_STATUSES = array( 'rejected', 'cancelled' );

	/**
	 * @param list<array{amount?: float|int|string, status?: string}> $payments
	 * @return array{amount_paid: float, amount_overdue: float}
	 */
	public static function summarize( array $payments ): array {
		$paid    = 0.0;
		$overdue = 0.0;

		foreach ( $payments as $payment ) {
			$amount = self::money( $payment['amount'] ?? 0 );
			$status = strtolower( trim( (string) ( $payment['status'] ?? '' ) ) );

			if ( in_array( $status, self::PAID_STATUSES, true ) ) {
				$paid += $amount;
			} elseif ( in_array( $status, self::OVERDUE_STATUSES, true ) ) {
				$overdue += $amount;
			}
		}

		return array(
			'amount_paid'    => self::money( $paid ),
			'amount_overdue' => self::money( $overdue ),
		);
	}

	/**
	 * Merge Debi sums with external settlement and derive remaining / overdue cap.
	 *
	 * @return array{amount_paid: float, amount_overdue: float, amount_remaining: float}
	 */
	public static function with_external( float $debi_paid, float $debi_overdue, float $external, float $target ): array {
		$paid      = self::money( $debi_paid + max( 0.0, $external ) );
		$remaining = self::money( max( 0.0, $target - $paid ) );
		$overdue   = self::money( min( $debi_overdue, $remaining ) );

		if ( $remaining <= 0.0 ) {
			$overdue = 0.0;
		}

		return array(
			'amount_paid'      => $paid,
			'amount_overdue'   => $overdue,
			'amount_remaining' => $remaining,
		);
	}

	public static function money( float|int|string $value ): float {
		return round( (float) $value, 2 );
	}
}
