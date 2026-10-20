<?php
/**
 * Unit tests for the mandate create param builder.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Checkout\MandateCreator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Checkout\MandateCreator
 */
final class MandateCreatorTest extends TestCase {

	public function test_build_params_includes_customer_description_and_metadata(): void {
		$params = MandateCreator::build_params(
			array(
				'payment_method_token' => 'PM_123',
				'description'          => 'Mandate - Order 7',
				'metadata'             => array(
					'blog_id'    => '3',
					'product_id' => '9',
					'wp_user_id' => '5',
				),
			),
			'CUST1'
		);

		$this->assertSame( 'PM_123', $params['payment_method_id'] );
		$this->assertSame( 'CUST1', $params['customer_id'] );
		$this->assertSame( 'Mandate - Order 7', $params['description'] );
		$this->assertSame(
			array(
				'blog_id'    => '3',
				'product_id' => '9',
				'wp_user_id' => '5',
			),
			$params['metadata']
		);
	}

	public function test_build_params_omits_empty_metadata(): void {
		$params = MandateCreator::build_params( array( 'payment_method_token' => 'PM_1' ), 'C1' );

		$this->assertArrayNotHasKey( 'metadata', $params );
		$this->assertSame( '', $params['description'] );
		$this->assertSame( 'C1', $params['customer_id'] );
	}

	public function test_build_params_trims_token(): void {
		$params = MandateCreator::build_params( array( 'payment_method_token' => '  PM_x  ' ), 'C1' );

		$this->assertSame( 'PM_x', $params['payment_method_id'] );
	}
}
