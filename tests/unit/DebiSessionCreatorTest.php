<?php
/**
 * Unit tests for the redirect-mode Debi session param builder.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DebiPro\Checkout\DebiSessionCreator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Checkout\DebiSessionCreator
 */
final class DebiSessionCreatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_blog_id' )->justReturn( 3 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function order( int $id ): \WC_Order {
		$order     = new \WC_Order();
		$order->id = $id;
		return $order;
	}

	public function test_payment_params_carry_metadata_and_customer(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'       => $this->order( 42 ),
				'amount'      => 1000.0,
				'success_url' => 'https://example.test/thanks',
				'description' => 'Order 42',
				'product_id'  => 7,
				'wp_user_id'  => 99,
				'customer'    => array(
					'name'                  => 'Doe, Jane',
					'email'                 => 'jane@example.test',
					'identification_number' => '20304050',
				),
			)
		);

		$this->assertSame( 'payment', $params['kind'] );
		$this->assertSame( 1000.0, $params['amount'] );
		$this->assertSame( 'https://example.test/thanks', $params['success_url'] );
		$this->assertTrue( $params['binary_mode'] );
		$this->assertSame( 'Doe, Jane', $params['customer_name'] );
		$this->assertSame( 'jane@example.test', $params['customer_email'] );
		$this->assertSame( '20304050', $params['customer_identification_number'] );
		$this->assertSame(
			array(
				'blog_id'    => '3',
				'order_id'   => '42',
				'product_id' => '7',
				'wp_user_id' => '99',
			),
			$params['metadata']
		);
		$this->assertArrayNotHasKey( 'installments', $params );
		$this->assertArrayNotHasKey( 'max_installments', $params );
		$this->assertArrayNotHasKey( 'editable_amount', $params );
	}

	public function test_fixed_installments_are_set(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'        => $this->order( 1 ),
				'amount'       => 500.0,
				'success_url'  => 'u',
				'installments' => 6,
			)
		);

		$this->assertSame( 6, $params['installments'] );
		$this->assertArrayNotHasKey( 'max_installments', $params );
	}

	public function test_max_installments_when_no_fixed_count(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'            => $this->order( 1 ),
				'amount'           => 500.0,
				'success_url'      => 'u',
				'max_installments' => 12,
			)
		);

		$this->assertSame( 12, $params['max_installments'] );
		$this->assertArrayNotHasKey( 'installments', $params );
	}

	public function test_fixed_count_wins_over_max(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'            => $this->order( 1 ),
				'amount'           => 500.0,
				'success_url'      => 'u',
				'installments'     => 3,
				'max_installments' => 12,
			)
		);

		$this->assertSame( 3, $params['installments'] );
		$this->assertArrayNotHasKey( 'max_installments', $params );
	}

	public function test_editable_amount_flag(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'           => $this->order( 1 ),
				'amount'          => 0.0,
				'success_url'     => 'u',
				'editable_amount' => true,
			)
		);

		$this->assertTrue( $params['editable_amount'] );
	}

	public function test_metadata_omits_user_for_guest_checkout(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'       => $this->order( 5 ),
				'amount'      => 10.0,
				'success_url' => 'u',
				'product_id'  => 2,
			)
		);

		$this->assertArrayNotHasKey( 'wp_user_id', $params['metadata'] );
		$this->assertSame( '2', $params['metadata']['product_id'] );
	}

	public function test_identification_omitted_when_empty(): void {
		$params = DebiSessionCreator::build_params(
			array(
				'order'       => $this->order( 5 ),
				'amount'      => 10.0,
				'success_url' => 'u',
			)
		);

		$this->assertArrayNotHasKey( 'customer_identification_number', $params );
	}
}
