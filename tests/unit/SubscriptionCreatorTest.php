<?php
/**
 * Unit tests for the subscription billing-day logic.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use DebiPro\Checkout\SubscriptionCreator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Checkout\SubscriptionCreator
 */
final class SubscriptionCreatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Invoke the private static billing_day_of_month() via reflection. */
	private function billing_day(): int {
		$method = new \ReflectionMethod( SubscriptionCreator::class, 'billing_day_of_month' );
		$method->setAccessible( true );
		return (int) $method->invoke( null );
	}

	/**
	 * The billing day is the site-local day of month, untouched.
	 *
	 * @dataProvider provide_days
	 */
	public function test_billing_day_is_local_day_unclamped( int $local_day ): void {
		Functions\when( 'current_time' )->alias(
			static function ( $format ) use ( $local_day ) {
				return 'j' === $format ? (string) $local_day : '';
			}
		);

		$this->assertSame( $local_day, $this->billing_day() );
	}

	/**
	 * Days that don't exist in every month (29–31) must NOT be clamped: Debi
	 * itself charges on the last day of short months, so the request keeps the
	 * real subscription day.
	 */
	public function provide_days(): array {
		return array(
			'first'        => array( 1 ),
			'mid'          => array( 15 ),
			'twenty_eight' => array( 28 ),
			'twenty_nine'  => array( 29 ),
			'thirty'       => array( 30 ),
			'thirty_one'   => array( 31 ),
		);
	}
}
