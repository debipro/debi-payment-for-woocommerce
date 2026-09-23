<?php
/**
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Projection\PaymentAmounts;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Projection\PaymentAmounts
 */
final class PaymentAmountsTest extends TestCase {

	public function test_summarize_splits_approved_and_overdue(): void {
		$sums = PaymentAmounts::summarize(
			array(
				array(
					'amount' => 100,
					'status' => 'approved',
				),
				array(
					'amount' => 100,
					'status' => 'rejected',
				),
				array(
					'amount' => 50,
					'status' => 'cancelled',
				),
				array(
					'amount' => 100,
					'status' => 'pending_submission',
				),
				array(
					'amount' => 100,
					'status' => 'submitted',
				),
			)
		);

		$this->assertSame( 100.0, $sums['amount_paid'] );
		$this->assertSame( 150.0, $sums['amount_overdue'] );
	}

	public function test_with_external_caps_overdue_and_remaining(): void {
		$merged = PaymentAmounts::with_external( 100.0, 200.0, 50.0, 400.0 );

		$this->assertSame( 150.0, $merged['amount_paid'] );
		$this->assertSame( 250.0, $merged['amount_remaining'] );
		$this->assertSame( 200.0, $merged['amount_overdue'] );
	}

	public function test_external_covering_debt_clears_overdue(): void {
		$merged = PaymentAmounts::with_external( 100.0, 300.0, 300.0, 400.0 );

		$this->assertSame( 400.0, $merged['amount_paid'] );
		$this->assertSame( 0.0, $merged['amount_remaining'] );
		$this->assertSame( 0.0, $merged['amount_overdue'] );
	}
}
