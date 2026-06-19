<?php
/**
 * Unit tests for per-product Debi financing meta.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DEBIPRO_Product_Meta;
use DebiProFinancingType;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/wp-meta-functions.php';

/**
 * @covers DEBIPRO_Product_Meta
 */
final class ProductMetaTest extends TestCase {

	private const PRODUCT_ID = 501;

	/** @var array<int, array<string, mixed>> */
	private array $meta_values = array();

	/** @var array<int, array<string, bool>> */
	private array $meta_exists = array();

	/** @var array<string, mixed> */
	private array $gateway_settings = array(
		'default_type'                        => 'installment',
		'default_monthly_interest_percentage' => '2',
		'default_installments'                => '2',
		'default_max_installments'            => '',
		'default_surcharge_percentage'        => '0',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta_values = array();
		$this->meta_exists = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_option' )->alias(
			function ( string $key ) {
				if ( 'woocommerce_debipro_settings' !== $key ) {
					return false;
				}

				return $this->gateway_settings;
			}
		);

		$meta_values = &$this->meta_values;
		$meta_exists = &$this->meta_exists;

		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key, bool $single = true ) use ( &$meta_values ): string {
				unset( $single );
				return isset( $meta_values[ $post_id ][ $key ] )
					? (string) $meta_values[ $post_id ][ $key ]
					: '';
			}
		);

		Functions\when( 'metadata_exists' )->alias(
			static function ( string $type, int $object_id, string $meta_key ) use ( &$meta_exists ): bool {
				unset( $type );
				return $meta_exists[ $object_id ][ $meta_key ] ?? false;
			}
		);

		Functions\when( 'update_post_meta' )->alias(
			function ( int $post_id, string $key, $value ): void {
				if ( ! isset( $this->meta_values[ $post_id ] ) ) {
					$this->meta_values[ $post_id ] = array();
				}
				$this->meta_values[ $post_id ][ $key ] = $value;
				$this->meta_exists[ $post_id ][ $key ] = true;
			}
		);

		Functions\when( 'delete_post_meta' )->alias(
			function ( int $post_id, string $key ): void {
				unset( $this->meta_values[ $post_id ][ $key ] );
				$this->meta_exists[ $post_id ][ $key ] = false;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function set_product_meta( string $key, string $value ): void {
		$this->meta_values[ self::PRODUCT_ID ][ $key ] = $value;
		$this->meta_exists[ self::PRODUCT_ID ][ $key ] = true;
	}

	public function test_get_product_financing_uses_global_fixed_default_when_product_has_no_meta(): void {
		$financing = DEBIPRO_Product_Meta::get_product_financing( self::PRODUCT_ID );

		$this->assertSame( DebiProFinancingType::Installment, $financing['type'] );
		$this->assertSame( 2, $financing['installments'] );
		$this->assertNull( $financing['max_installments'] );
	}

	public function test_get_product_financing_does_not_apply_fixed_default_when_product_uses_max_installments(): void {
		$this->set_product_meta( DEBIPRO_Product_Meta::MAX_INST_KEY, '6' );

		$financing = DEBIPRO_Product_Meta::get_product_financing( self::PRODUCT_ID );

		$this->assertNull( $financing['installments'] );
		$this->assertSame( 6, $financing['max_installments'] );
	}

	public function test_get_product_financing_does_not_apply_max_default_when_product_uses_fixed_installments(): void {
		$this->gateway_settings['default_max_installments'] = '12';
		$this->set_product_meta( DEBIPRO_Product_Meta::INSTALL_KEY, '3' );

		$financing = DEBIPRO_Product_Meta::get_product_financing( self::PRODUCT_ID );

		$this->assertSame( 3, $financing['installments'] );
		$this->assertNull( $financing['max_installments'] );
	}

	public function test_get_product_financing_prefers_product_fixed_over_global_max_default(): void {
		$this->gateway_settings['default_max_installments'] = '12';
		$this->set_product_meta( DEBIPRO_Product_Meta::INSTALL_KEY, '4' );

		$financing = DEBIPRO_Product_Meta::get_product_financing( self::PRODUCT_ID );

		$this->assertSame( 4, $financing['installments'] );
		$this->assertNull( $financing['max_installments'] );
	}

	public function test_save_product_meta_keeps_only_fixed_installments_when_both_submitted(): void {
		$_POST = array(
			DEBIPRO_Product_Meta::TYPE_KEY     => DebiProFinancingType::Installment->value,
			DEBIPRO_Product_Meta::INSTALL_KEY  => '3',
			DEBIPRO_Product_Meta::MAX_INST_KEY => '6',
		);

		DEBIPRO_Product_Meta::save_product_meta( self::PRODUCT_ID );

		$this->assertSame( 3, $this->meta_values[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::INSTALL_KEY ] ?? null );
		$this->assertFalse( $this->meta_exists[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::MAX_INST_KEY ] ?? true );
	}

	public function test_save_product_meta_keeps_only_max_installments_when_both_submitted(): void {
		$this->set_product_meta( DEBIPRO_Product_Meta::INSTALL_KEY, '2' );

		$_POST = array(
			DEBIPRO_Product_Meta::TYPE_KEY     => DebiProFinancingType::Installment->value,
			DEBIPRO_Product_Meta::INSTALL_KEY  => '',
			DEBIPRO_Product_Meta::MAX_INST_KEY => '8',
		);

		DEBIPRO_Product_Meta::save_product_meta( self::PRODUCT_ID );

		$this->assertSame( 8, $this->meta_values[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::MAX_INST_KEY ] ?? null );
		$this->assertFalse( $this->meta_exists[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::INSTALL_KEY ] ?? true );
	}

	public function test_save_product_meta_clears_both_when_neither_is_set(): void {
		$this->set_product_meta( DEBIPRO_Product_Meta::INSTALL_KEY, '2' );
		$this->set_product_meta( DEBIPRO_Product_Meta::MAX_INST_KEY, '6' );

		$_POST = array(
			DEBIPRO_Product_Meta::TYPE_KEY     => DebiProFinancingType::Installment->value,
			DEBIPRO_Product_Meta::INSTALL_KEY  => '',
			DEBIPRO_Product_Meta::MAX_INST_KEY => '',
		);

		DEBIPRO_Product_Meta::save_product_meta( self::PRODUCT_ID );

		$this->assertFalse( $this->meta_exists[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::INSTALL_KEY ] ?? true );
		$this->assertFalse( $this->meta_exists[ self::PRODUCT_ID ][ DEBIPRO_Product_Meta::MAX_INST_KEY ] ?? true );
	}

	public function test_saved_max_installments_prevents_global_fixed_default_on_read(): void {
		$_POST = array(
			DEBIPRO_Product_Meta::TYPE_KEY     => DebiProFinancingType::Installment->value,
			DEBIPRO_Product_Meta::INSTALL_KEY  => '',
			DEBIPRO_Product_Meta::MAX_INST_KEY => '5',
		);

		DEBIPRO_Product_Meta::save_product_meta( self::PRODUCT_ID );
		$financing = DEBIPRO_Product_Meta::get_product_financing( self::PRODUCT_ID );

		$this->assertNull( $financing['installments'] );
		$this->assertSame( 5, $financing['max_installments'] );
	}
}
