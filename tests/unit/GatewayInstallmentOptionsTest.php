<?php
/**
 * Unit tests for the installment-plan option builder that backs the live cuotas
 * selector (classic + blocks checkout and the debipro/v1/installments endpoint).
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DEBIPRO_Payment_Gateway;
use DEBIPRO_Product_Meta;
use PHPUnit\Framework\TestCase;

require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-keys.php';
require_once __DIR__ . '/../stubs/wp-meta-functions.php';
require_once __DIR__ . '/../stubs/class-wc-payment-gateway.php';
require_once __DIR__ . '/../stubs/class-wc-admin-settings.php';
require_once DEBI_PLUGIN_DIR . '/class-debipro-payment-gateway.php';

/**
 * @covers DEBIPRO_Payment_Gateway::installment_options_for_product
 */
final class GatewayInstallmentOptionsTest extends TestCase {

	private DEBIPRO_Payment_Gateway $gateway;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'metadata_exists' )->justReturn( false );

		$this->gateway = new class() extends DEBIPRO_Payment_Gateway {
			public function __construct() {
				$this->id = 'debipro';
			}
		};
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $meta Product meta keyed by its storage key.
	 */
	private function stub_product_meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( $pid, $key, $single = false ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
	}

	public function test_non_installment_product_has_no_options(): void {
		$this->stub_product_meta(
			array(
				DEBIPRO_Product_Meta::SESSION_TYPE_KEY => 'payment',
				DEBIPRO_Product_Meta::INTEREST_KEY     => '0',
				DEBIPRO_Product_Meta::SURCHARGE_KEY    => '0',
			)
		);

		$this->assertSame( array(), $this->gateway->installment_options_for_product( 10, 5000.0 ) );
	}

	public function test_max_installments_builds_one_option_per_count(): void {
		$this->stub_product_meta(
			array(
				DEBIPRO_Product_Meta::SESSION_TYPE_KEY => 'installment',
				DEBIPRO_Product_Meta::INTEREST_KEY     => '0',
				DEBIPRO_Product_Meta::SURCHARGE_KEY    => '0',
				DEBIPRO_Product_Meta::MAX_INST_KEY     => '3',
			)
		);

		$options = $this->gateway->installment_options_for_product( 10, 3000.0 );

		$this->assertCount( 3, $options );
		$this->assertSame( array( 1, 2, 3 ), array_column( $options, 'value' ) );
		foreach ( $options as $opt ) {
			$this->assertArrayHasKey( 'label', $opt );
			$this->assertNotSame( '', $opt['label'] );
		}
	}

	public function test_fixed_installments_builds_single_option(): void {
		$this->stub_product_meta(
			array(
				DEBIPRO_Product_Meta::SESSION_TYPE_KEY => 'installment',
				DEBIPRO_Product_Meta::INTEREST_KEY     => '0',
				DEBIPRO_Product_Meta::SURCHARGE_KEY    => '0',
				DEBIPRO_Product_Meta::INSTALL_KEY      => '6',
			)
		);

		$options = $this->gateway->installment_options_for_product( 10, 6000.0 );

		$this->assertCount( 1, $options );
		$this->assertSame( 6, $options[0]['value'] );
	}

	public function test_zero_product_id_returns_no_options(): void {
		$this->assertSame( array(), $this->gateway->installment_options_for_product( 0, 1000.0 ) );
	}
}
