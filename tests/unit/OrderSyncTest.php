<?php
/**
 * Unit tests for the webhook → order projection entry point.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Brain\Monkey;
use DebiPro\Webhook\OrderSync;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Webhook\OrderSync
 */
final class OrderSyncTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		OrderSync::set_client_factory( null );
	}

	protected function tearDown(): void {
		OrderSync::set_client_factory( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_subscription_event_without_id_is_reported(): void {
		$result = OrderSync::handle( 'EV1', 'subscription.finished', '', '' );
		$this->assertSame( 'missing_subscription_id', $result );
	}

	public function test_subscription_event_syncs_via_client(): void {
		// With an id but no configured client, it reaches for the client and fails
		// there (rather than being ignored like before) — it now mirrors the table.
		$result = OrderSync::handle( 'EV1', 'subscription.finished', '', 'SUB1' );
		$this->assertSame( 'client_error', $result );
	}

	public function test_unknown_event_type_is_ignored(): void {
		$result = OrderSync::handle( 'EV1', 'customer.updated', 'PY1', '' );
		$this->assertSame( 'ignored', $result );
	}

	public function test_non_created_mandate_event_is_ignored(): void {
		$result = OrderSync::handle( 'EV1', 'mandate.revoked', '', '', 'MA1' );
		$this->assertSame( 'ignored', $result );
	}

	public function test_mandate_created_without_id_is_reported(): void {
		$result = OrderSync::handle( 'EV1', 'mandate.created', '', '', '' );
		$this->assertSame( 'missing_mandate_id', $result );
	}

	public function test_mandate_created_reaches_client_when_unconfigured(): void {
		// With an id and no existing order for it, it reaches for the client and
		// fails there (no gateway settings → DebiClientFactory throws).
		$result = OrderSync::handle( 'EV1', 'mandate.created', '', '', 'MA1' );
		$this->assertSame( 'client_error', $result );
	}

	public function test_missing_ids_are_reported(): void {
		$result = OrderSync::handle( 'EV1', 'payment.updated', '', '' );
		$this->assertSame( 'missing_payment_id', $result );
	}

	public function test_client_error_when_unconfigured(): void {
		// No factory and no gateway settings → DebiClientFactory throws.
		$result = OrderSync::handle( 'EV1', 'payment.updated', 'PY1', 'SUB1' );
		$this->assertSame( 'client_error', $result );
	}
}
