<?php
/**
 * Unit tests for the uniform Debi metadata builder shared by every checkout flow
 * (embedded payment/installment, embedded subscription, embedded mandate). It is
 * what lets the webhook attribute a resulting payment/subscription to its store
 * product (primary key) and originating order.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DEBIPRO_Payment_Gateway;
use PHPUnit\Framework\TestCase;
use WC_Order;

require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-keys.php';
require_once __DIR__ . '/../stubs/wp-meta-functions.php';
require_once __DIR__ . '/../stubs/class-wc-payment-gateway.php';
require_once __DIR__ . '/../stubs/class-wc-admin-settings.php';
require_once __DIR__ . '/../stubs/class-wc-order.php';
require_once DEBI_PLUGIN_DIR . '/class-debipro-payment-gateway.php';

/**
 * @covers DEBIPRO_Payment_Gateway::build_debi_metadata
 */
final class GatewayMetadataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_current_blog_id' )->justReturn( 7 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_metadata_always_carries_product_and_order(): void {
		$order              = new WC_Order();
		$order->id          = 321;
		$order->customer_id = 55;

		$metadata = DEBIPRO_Payment_Gateway::build_debi_metadata( $order, 99 );

		$this->assertSame( '99', $metadata['product_id'] );
		$this->assertSame( '321', $metadata['order_id'] );
		$this->assertSame( '7', $metadata['blog_id'] );
		$this->assertSame( '55', $metadata['wp_user_id'] );
	}

	public function test_guest_order_omits_wp_user_id_but_keeps_product(): void {
		$order              = new WC_Order();
		$order->id          = 10;
		$order->customer_id = 0;

		$metadata = DEBIPRO_Payment_Gateway::build_debi_metadata( $order, 42 );

		$this->assertSame( '42', $metadata['product_id'] );
		$this->assertSame( '10', $metadata['order_id'] );
		$this->assertArrayNotHasKey( 'wp_user_id', $metadata );
	}
}
