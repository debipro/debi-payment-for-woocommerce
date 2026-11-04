<?php
/**
 * Unit tests for the one-off payment request builder.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Checkout\PaymentCreator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Checkout\PaymentCreator
 */
final class PaymentCreatorTest extends TestCase {

	/**
	 * The body carries the required amount / description / customer_id and the
	 * payment-method token, matching Debi's `POST /v1/payments` contract.
	 */
	public function test_build_params_has_required_charge_fields(): void {
		$params = PaymentCreator::build_params( 1500.0, 'Order 7', 'PMabc', 'CSxyz', array() );

		$this->assertSame(
			array(
				'amount'            => 1500.0,
				'description'       => 'Order 7',
				'payment_method_id' => 'PMabc',
				'customer_id'       => 'CSxyz',
			),
			$params
		);
	}

	/** Currency is intentionally omitted so Debi applies the account default. */
	public function test_build_params_omits_currency(): void {
		$params = PaymentCreator::build_params( 10.0, 'x', 'PM', 'CS', array() );

		$this->assertArrayNotHasKey( 'currency', $params );
	}

	/** Metadata is included only when non-empty. */
	public function test_build_params_includes_metadata_when_present(): void {
		$meta   = array(
			'blog_id'    => '2',
			'order_id'   => '7',
			'product_id' => '42',
		);
		$params = PaymentCreator::build_params( 10.0, 'x', 'PM', 'CS', $meta );

		$this->assertSame( $meta, $params['metadata'] );
	}

	/** An empty metadata array is dropped entirely (no `metadata` key). */
	public function test_build_params_omits_empty_metadata(): void {
		$params = PaymentCreator::build_params( 10.0, 'x', 'PM', 'CS', array() );

		$this->assertArrayNotHasKey( 'metadata', $params );
	}
}
