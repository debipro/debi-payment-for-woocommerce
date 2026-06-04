<?php
/**
 * Unit tests for the webhook → order status mapping.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DebiPro\Webhook\OrderSync;
use PHPUnit\Framework\TestCase;

/**
 * The global WC_Order test double lives in tests/bootstrap.php.
 *
 * @covers \DebiPro\Webhook\OrderSync
 */
final class OrderSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Make wc_get_orders resolve to the given order (or none). */
	private function stub_order_lookup( ?\WC_Order $order ): void {
		Functions\when( 'wc_get_orders' )->justReturn( null === $order ? array() : array( $order ) );
	}

	public function test_unknown_event_type_is_ignored(): void {
		// No order lookup should even be attempted.
		$result = OrderSync::handle( 'EV1', 'subscription.updated', 'SUB1' );
		$this->assertSame( 'ignored', $result );
	}

	public function test_missing_subscription_id_is_reported(): void {
		$result = OrderSync::handle( 'EV1', 'subscription.finished', '' );
		$this->assertSame( 'missing_subscription_id', $result );
	}

	public function test_order_not_found_is_reported(): void {
		$this->stub_order_lookup( null );
		$result = OrderSync::handle( 'EV1', 'subscription.finished', 'SUBmissing' );
		$this->assertSame( 'order_not_found', $result );
	}

	public function test_finished_moves_order_to_completed(): void {
		$order         = new \WC_Order();
		$order->status = 'processing';
		$this->stub_order_lookup( $order );

		$result = OrderSync::handle( 'EVfin', 'subscription.finished', 'SUB1' );

		$this->assertSame( 'updated_completed', $result );
		$this->assertSame( 'completed', $order->status );
		$this->assertTrue( $order->saved );
		$this->assertContains( 'EVfin', $order->meta['_debipro_processed_events'] );
	}

	public function test_cancelled_moves_order_to_cancelled(): void {
		$order         = new \WC_Order();
		$order->status = 'processing';
		$this->stub_order_lookup( $order );

		$result = OrderSync::handle( 'EVcan', 'subscription.cancelled', 'SUB1' );

		$this->assertSame( 'updated_cancelled', $result );
		$this->assertSame( 'cancelled', $order->status );
	}

	public function test_already_in_target_status_is_a_noop(): void {
		$order         = new \WC_Order();
		$order->status = 'completed';
		$this->stub_order_lookup( $order );

		$result = OrderSync::handle( 'EVfin', 'subscription.finished', 'SUB1' );

		$this->assertSame( 'noop_already_completed', $result );
		$this->assertSame( 'completed', $order->status );
		// The event id is still recorded so a later replay short-circuits.
		$this->assertContains( 'EVfin', $order->meta['_debipro_processed_events'] );
	}

	public function test_replayed_event_id_is_skipped(): void {
		$order                                    = new \WC_Order();
		$order->status                            = 'processing';
		$order->meta['_debipro_processed_events'] = array( 'EVfin' );
		$this->stub_order_lookup( $order );

		$result = OrderSync::handle( 'EVfin', 'subscription.finished', 'SUB1' );

		$this->assertSame( 'duplicate', $result );
		// Status untouched by the duplicate delivery.
		$this->assertSame( 'processing', $order->status );
	}
}
