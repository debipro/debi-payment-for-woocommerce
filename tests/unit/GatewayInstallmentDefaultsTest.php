<?php
/**
 * Unit tests for gateway default installment settings validation.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DEBIPRO_Payment_Gateway;
use PHPUnit\Framework\TestCase;

require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-keys.php';
require_once __DIR__ . '/../stubs/wp-meta-functions.php';
require_once __DIR__ . '/../stubs/class-wc-payment-gateway.php';
require_once __DIR__ . '/../stubs/class-wc-admin-settings.php';
require_once DEBI_PLUGIN_DIR . '/class-debipro-payment-gateway.php';

/**
 * @covers DEBIPRO_Payment_Gateway::process_admin_options
 */
final class GatewayInstallmentDefaultsTest extends TestCase {

	/** @var DEBIPRO_Payment_Gateway&object{__construct(): void} */
	private DEBIPRO_Payment_Gateway $gateway;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( '__' )->returnArg( 1 );
		\WC_Admin_Settings::reset_errors();

		$this->gateway = new class() extends DEBIPRO_Payment_Gateway {
			public function __construct() {
				$this->id        = 'debipro';
				$this->plugin_id = 'woocommerce_';
			}
		};
	}

	protected function tearDown(): void {
		$_POST = array();
		\WC_Admin_Settings::reset_errors();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $fields
	 */
	private function submit_settings( array $fields ): bool {
		$_POST = array();
		foreach ( $fields as $key => $value ) {
			$_POST[ $this->gateway->get_field_key( $key ) ] = $value;
		}

		return (bool) $this->gateway->process_admin_options();
	}

	private function post_field( string $key ): string {
		$post_data = $this->gateway->get_post_data();

		return (string) ( $post_data[ $this->gateway->get_field_key( $key ) ] ?? '' );
	}

	public function test_process_admin_options_requires_one_installment_default_for_installment_type(): void {
		$saved = $this->submit_settings(
			array(
				'secret_key'               => 'sk_test_abc',
				'publishable_key'          => 'pk_test_xyz',
				'default_type'             => 'installment',
				'default_installments'     => '',
				'default_max_installments' => '',
			)
		);

		$this->assertFalse( $saved );
		$this->assertNotEmpty( \WC_Admin_Settings::$errors );
	}

	public function test_process_admin_options_accepts_fixed_installments_only(): void {
		$saved = $this->submit_settings(
			array(
				'secret_key'               => 'sk_test_abc',
				'publishable_key'          => 'pk_test_xyz',
				'default_type'             => 'installment',
				'default_installments'     => '2',
				'default_max_installments' => '',
			)
		);

		$this->assertTrue( $saved );
		$this->assertSame( '', $this->post_field( 'default_max_installments' ) );
	}

	public function test_process_admin_options_accepts_max_installments_only(): void {
		$saved = $this->submit_settings(
			array(
				'secret_key'               => 'sk_test_abc',
				'publishable_key'          => 'pk_test_xyz',
				'default_type'             => 'installment',
				'default_installments'     => '',
				'default_max_installments' => '12',
			)
		);

		$this->assertTrue( $saved );
		$this->assertSame( '', $this->post_field( 'default_installments' ) );
	}

	public function test_process_admin_options_prefers_fixed_when_both_defaults_submitted(): void {
		$saved = $this->submit_settings(
			array(
				'secret_key'               => 'sk_test_abc',
				'publishable_key'          => 'pk_test_xyz',
				'default_type'             => 'installment',
				'default_installments'     => '2',
				'default_max_installments' => '12',
			)
		);

		$this->assertTrue( $saved );
		$this->assertSame( '2', $this->post_field( 'default_installments' ) );
		$this->assertSame( '', $this->post_field( 'default_max_installments' ) );
	}

	public function test_process_admin_options_skips_installment_requirement_for_one_time_type(): void {
		$saved = $this->submit_settings(
			array(
				'secret_key'               => 'sk_test_abc',
				'publishable_key'          => 'pk_test_xyz',
				'default_type'             => 'one_time',
				'default_installments'     => '',
				'default_max_installments' => '',
			)
		);

		$this->assertTrue( $saved );
		$this->assertSame( array(), \WC_Admin_Settings::$errors );
	}
}
