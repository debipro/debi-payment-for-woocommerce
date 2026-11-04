<?php
/**
 * Unit tests for the single-subscription reconcile path.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use DebiPro\Subscription\SubscriptionReconciler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Subscription\SubscriptionReconciler
 */
final class SubscriptionReconcilerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		SubscriptionReconciler::set_client_factory( null );
	}

	protected function tearDown(): void {
		SubscriptionReconciler::set_client_factory( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_empty_id_is_reported(): void {
		$this->assertSame( 'missing_id', SubscriptionReconciler::reconcile_one( '' ) );
	}

	public function test_whitespace_id_is_treated_as_missing(): void {
		$this->assertSame( 'missing_id', SubscriptionReconciler::reconcile_one( "  \t " ) );
	}

	public function test_client_error_when_unconfigured(): void {
		// No factory and no gateway settings → DebiClientFactory::create() throws,
		// which the reconciler reports as a client error rather than fataling.
		$this->assertSame( 'client_error', SubscriptionReconciler::reconcile_one( 'SB1' ) );
	}

	public function test_client_factory_throwing_is_client_error(): void {
		SubscriptionReconciler::set_client_factory(
			static function () {
				throw new \RuntimeException( 'cannot build client' );
			}
		);
		// A throwing factory must be caught and reported, never fatal the request.
		$this->assertSame( 'client_error', SubscriptionReconciler::reconcile_one( 'SB1' ) );
	}

	public function test_cancel_empty_id_is_reported(): void {
		$this->assertSame( 'missing_id', SubscriptionReconciler::cancel_one( '  ' ) );
	}

	public function test_cancel_client_error_when_unconfigured(): void {
		// No factory and no gateway settings → DebiClientFactory::create() throws.
		$this->assertSame( 'client_error', SubscriptionReconciler::cancel_one( 'SB1' ) );
	}
}
