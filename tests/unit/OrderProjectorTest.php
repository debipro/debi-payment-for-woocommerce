<?php
/**
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Projection\OrderMeta;
use DebiPro\Projection\OrderProjector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Projection\OrderProjector
 */
final class OrderProjectorTest extends TestCase {

	public function test_checkout_plan_projects_partial_payments(): void {
		$order         = new \WC_Order();
		$order->status = 'processing';
		$order->update_meta_data( OrderMeta::ORIGIN, OrderMeta::ORIGIN_INSTALLMENT_PLAN );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, '300' );

		$result = OrderProjector::apply(
			$order,
			'active',
			array(
				array(
					'amount' => 100,
					'status' => 'approved',
				),
				array(
					'amount' => 100,
					'status' => 'rejected',
				),
			)
		);

		$this->assertSame( 'projected_past_due', $result );
		$this->assertSame( OrderMeta::STATUS_PAST_DUE, $order->get_meta( OrderMeta::PAYMENT_STATUS ) );
		$this->assertSame( '100', $order->get_meta( OrderMeta::AMOUNT_PAID ) );
		$this->assertSame( '100', $order->get_meta( OrderMeta::AMOUNT_OVERDUE ) );
		$this->assertSame( '200', $order->get_meta( OrderMeta::AMOUNT_REMAINING ) );
		$this->assertSame( 'processing', $order->status );
	}

	public function test_finished_and_paid_completes(): void {
		$order = new \WC_Order();
		$order->update_meta_data( OrderMeta::ORIGIN, OrderMeta::ORIGIN_INSTALLMENT_PLAN );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, '200' );

		$result = OrderProjector::apply(
			$order,
			'finished',
			array(
				array(
					'amount' => 100,
					'status' => 'approved',
				),
				array(
					'amount' => 100,
					'status' => 'approved',
				),
			)
		);

		$this->assertSame( 'updated_completed', $result );
		$this->assertSame( 'completed', $order->status );
		$this->assertSame( OrderMeta::STATUS_PAID, $order->get_meta( OrderMeta::PAYMENT_STATUS ) );
	}

	public function test_subscription_cancel_cancels_order(): void {
		$order = new \WC_Order();
		$order->update_meta_data( OrderMeta::ORIGIN, OrderMeta::ORIGIN_INSTALLMENT_PLAN );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, '200' );

		OrderProjector::apply(
			$order,
			'cancelled',
			array(
				array(
					'amount' => 100,
					'status' => 'approved',
				),
				array(
					'amount' => 100,
					'status' => 'cancelled',
				),
			)
		);

		$this->assertSame( 'cancelled', $order->status );
		$this->assertSame( OrderMeta::STATUS_CANCELLED, $order->get_meta( OrderMeta::PAYMENT_STATUS ) );
		$this->assertSame( '100', $order->get_meta( OrderMeta::AMOUNT_OVERDUE ) );
	}

	public function test_inbound_does_not_cancel_on_rejected_payment(): void {
		$order = new \WC_Order();
		$order->update_meta_data( OrderMeta::ORIGIN, OrderMeta::ORIGIN_DEBI_SUBSCRIPTION_PAYMENT );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, '80' );

		OrderProjector::apply(
			$order,
			'active',
			array(
				array(
					'amount' => 80,
					'status' => 'rejected',
				),
			)
		);

		$this->assertSame( 'processing', $order->status );
		$this->assertSame( OrderMeta::STATUS_PAST_DUE, $order->get_meta( OrderMeta::PAYMENT_STATUS ) );
	}
}
