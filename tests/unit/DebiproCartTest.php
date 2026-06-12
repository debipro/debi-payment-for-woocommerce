<?php
/**
 * Unit tests for Debi cart financing-type validation.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DEBIPRO_Cart;
use DebiProFinancingType;
use PHPUnit\Framework\TestCase;

/**
 * @covers DEBIPRO_Cart
 */
final class DebiproCartTest extends TestCase {

	private const PRODUCT_SUBSCRIPTION = 101;
	private const PRODUCT_INSTALLMENT  = 102;
	private const PRODUCT_ONE_TIME_A   = 103;
	private const PRODUCT_ONE_TIME_B   = 104;

	/** @var array<int, array{message: string, type: string}> */
	private array $notices = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->notices = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $post_id, string $key ): string {
				if ( '_debipro_type' !== $key ) {
					return '';
				}

				$types = array(
					self::PRODUCT_SUBSCRIPTION => DebiProFinancingType::Subscription->value,
					self::PRODUCT_INSTALLMENT  => DebiProFinancingType::Installment->value,
					self::PRODUCT_ONE_TIME_A   => DebiProFinancingType::OneTimePayment->value,
					self::PRODUCT_ONE_TIME_B   => DebiProFinancingType::OneTimePayment->value,
				);

				return $types[ $post_id ] ?? '';
			}
		);

		$notices = &$this->notices;
		Functions\when( 'wc_add_notice' )->alias(
			static function ( string $message, string $type ) use ( &$notices ): void {
				$notices[] = array(
					'message' => $message,
					'type'    => $type,
				);
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['debipro_wc_stub'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, array{product_id: int}> $items
	 */
	private function stub_cart( array $items ): void {
		$cart = new class( $items ) {
			/** @param array<string, array{product_id: int}> $items */
			public function __construct( private array $items ) {}

			/** @return array<string, array{product_id: int}> */
			public function get_cart(): array {
				return $this->items;
			}
		};

		$wc = new class( $cart ) {
			public function __construct( public object $cart ) {}
		};

		$GLOBALS['debipro_wc_stub'] = $wc;
	}

	private function invoke_types_are_compatible(
		DebiProFinancingType $reference,
		DebiProFinancingType $new
	): bool {
		$method = new \ReflectionMethod( DEBIPRO_Cart::class, 'types_are_compatible' );
		$method->setAccessible( true );

		return (bool) $method->invoke( null, $reference, $new );
	}

	/** @param array<string, array{product_id: int}> $items */
	private function invoke_last_cart_item_type( array $items ): ?DebiProFinancingType {
		$method = new \ReflectionMethod( DEBIPRO_Cart::class, 'get_last_cart_item_type' );
		$method->setAccessible( true );

		return $method->invoke( null, $items );
	}

	/**
	 * @dataProvider provide_type_compatibility_matrix
	 */
	public function test_types_are_compatible_matrix(
		DebiProFinancingType $reference,
		DebiProFinancingType $new,
		bool $expected
	): void {
		$this->assertSame( $expected, $this->invoke_types_are_compatible( $reference, $new ) );
	}

	public function provide_type_compatibility_matrix(): array {
		$exclusive = array(
			DebiProFinancingType::Subscription,
			DebiProFinancingType::Installment,
			DebiProFinancingType::OneTimePayment,
		);

		$cases = array();
		foreach ( $exclusive as $reference ) {
			foreach ( $exclusive as $new ) {
				$expected = DebiProFinancingType::OneTimePayment === $reference
					&& DebiProFinancingType::OneTimePayment === $new;

				$cases[ $reference->value . '_with_' . $new->value ] = array(
					$reference,
					$new,
					$expected,
				);
			}
		}

		return $cases;
	}

	public function test_get_last_cart_item_type_uses_last_entry(): void {
		$items = array(
			'first'  => array( 'product_id' => self::PRODUCT_ONE_TIME_A ),
			'second' => array( 'product_id' => self::PRODUCT_SUBSCRIPTION ),
		);

		$this->assertSame(
			DebiProFinancingType::Subscription,
			$this->invoke_last_cart_item_type( $items )
		);
	}

	public function test_validate_add_to_cart_allows_any_product_in_empty_cart(): void {
		$this->stub_cart( array() );

		$result = DEBIPRO_Cart::validate_add_to_cart( true, self::PRODUCT_SUBSCRIPTION, 1 );

		$this->assertTrue( $result );
		$this->assertSame( array(), $this->notices );
	}

	public function test_validate_add_to_cart_allows_multiple_one_time_products(): void {
		$this->stub_cart(
			array(
				'line_a' => array( 'product_id' => self::PRODUCT_ONE_TIME_A ),
			)
		);

		$result = DEBIPRO_Cart::validate_add_to_cart( true, self::PRODUCT_ONE_TIME_B, 1 );

		$this->assertTrue( $result );
		$this->assertSame( array(), $this->notices );
	}

	/**
	 * @dataProvider provide_blocked_add_to_cart_cases
	 *
	 * @param array<string, array{product_id: int}> $cart_items
	 */
	public function test_validate_add_to_cart_blocks_incompatible_products(
		array $cart_items,
		int $new_product_id,
		string $expected_message
	): void {
		$this->stub_cart( $cart_items );

		$result = DEBIPRO_Cart::validate_add_to_cart( true, $new_product_id, 1 );

		$this->assertFalse( $result );
		$this->assertCount( 1, $this->notices );
		$this->assertSame( 'error', $this->notices[0]['type'] );
		$this->assertSame( $expected_message, $this->notices[0]['message'] );
	}

	public function provide_blocked_add_to_cart_cases(): array {
		$cart_has_exclusive = 'The cart already contains a subscription or installment product. You cannot add more products.';
		$adding_exclusive   = 'You cannot add a subscription or installment product together with other products. Please empty the cart first.';

		return array(
			'one_time_then_subscription' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_ONE_TIME_A ) ),
				self::PRODUCT_SUBSCRIPTION,
				$adding_exclusive,
			),
			'one_time_then_installment' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_ONE_TIME_A ) ),
				self::PRODUCT_INSTALLMENT,
				$adding_exclusive,
			),
			'subscription_then_one_time' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_SUBSCRIPTION ) ),
				self::PRODUCT_ONE_TIME_A,
				$cart_has_exclusive,
			),
			'subscription_then_installment' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_SUBSCRIPTION ) ),
				self::PRODUCT_INSTALLMENT,
				$cart_has_exclusive,
			),
			'installment_then_one_time' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_INSTALLMENT ) ),
				self::PRODUCT_ONE_TIME_A,
				$cart_has_exclusive,
			),
			'installment_then_subscription' => array(
				array( 'line' => array( 'product_id' => self::PRODUCT_INSTALLMENT ) ),
				self::PRODUCT_SUBSCRIPTION,
				$cart_has_exclusive,
			),
		);
	}

	public function test_validate_add_to_cart_checks_against_last_item_not_first(): void {
		$this->stub_cart(
			array(
				'older' => array( 'product_id' => self::PRODUCT_ONE_TIME_A ),
				'last'  => array( 'product_id' => self::PRODUCT_SUBSCRIPTION ),
			)
		);

		$result = DEBIPRO_Cart::validate_add_to_cart( true, self::PRODUCT_ONE_TIME_B, 1 );

		$this->assertFalse( $result );
		$this->assertSame(
			'The cart already contains a subscription or installment product. You cannot add more products.',
			$this->notices[0]['message']
		);
	}

	public function test_validate_add_to_cart_skips_when_prior_validation_failed(): void {
		$this->stub_cart(
			array(
				'line' => array( 'product_id' => self::PRODUCT_SUBSCRIPTION ),
			)
		);

		$result = DEBIPRO_Cart::validate_add_to_cart( false, self::PRODUCT_ONE_TIME_A, 1 );

		$this->assertFalse( $result );
		$this->assertSame( array(), $this->notices );
	}
}
