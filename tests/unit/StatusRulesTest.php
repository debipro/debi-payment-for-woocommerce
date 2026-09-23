<?php
/**
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Projection\OrderMeta;
use DebiPro\Projection\StatusRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Projection\StatusRules
 */
final class StatusRulesTest extends TestCase {

	public function test_checkout_active_current(): void {
		$r = StatusRules::for_checkout_plan( 'active', 100.0, 0.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_CURRENT, $r['payment_status'] );
		$this->assertSame( 'processing', $r['wc_status'] );
	}

	public function test_checkout_active_past_due(): void {
		$r = StatusRules::for_checkout_plan( 'active', 100.0, 100.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_PAST_DUE, $r['payment_status'] );
		$this->assertSame( 'processing', $r['wc_status'] );
	}

	public function test_checkout_finished_requires_full_pay(): void {
		$short = StatusRules::for_checkout_plan( 'finished', 300.0, 0.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_CURRENT, $short['payment_status'] );
		$this->assertSame( 'processing', $short['wc_status'] );

		$done = StatusRules::for_checkout_plan( 'finished', 400.0, 0.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_PAID, $done['payment_status'] );
		$this->assertSame( 'completed', $done['wc_status'] );
	}

	public function test_checkout_cancelled_without_full_pay(): void {
		$r = StatusRules::for_checkout_plan( 'cancelled', 100.0, 100.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_CANCELLED, $r['payment_status'] );
		$this->assertSame( 'cancelled', $r['wc_status'] );
	}

	public function test_checkout_cancelled_with_full_pay_completes(): void {
		$r = StatusRules::for_checkout_plan( 'cancelled', 400.0, 0.0, 400.0 );
		$this->assertSame( OrderMeta::STATUS_PAID, $r['payment_status'] );
		$this->assertSame( 'completed', $r['wc_status'] );
	}

	public function test_inbound_approved_completes(): void {
		$r = StatusRules::for_inbound_payment( 50.0, 0.0, 50.0 );
		$this->assertSame( OrderMeta::STATUS_PAID, $r['payment_status'] );
		$this->assertSame( 'completed', $r['wc_status'] );
	}

	public function test_inbound_rejected_stays_processing(): void {
		$r = StatusRules::for_inbound_payment( 0.0, 50.0, 50.0 );
		$this->assertSame( OrderMeta::STATUS_PAST_DUE, $r['payment_status'] );
		$this->assertSame( 'processing', $r['wc_status'] );
	}
}
