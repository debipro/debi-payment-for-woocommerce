<?php
/**
 * Pins the Debi webhook event set the installer subscribes endpoints to. The
 * webhook handler acts on these events, so the subscribed set must stay in sync
 * with what OrderSync processes (payment lifecycle + subscription end states).
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use DebiPro\Webhook\WebhookInstaller;
use PHPUnit\Framework\TestCase;

/**
 * @covers \DebiPro\Webhook\WebhookInstaller
 */
final class WebhookInstallerEventsTest extends TestCase {

	public function test_subscribes_to_payment_and_subscription_lifecycle(): void {
		$events = WebhookInstaller::EVENTS;

		foreach (
			array(
				'payment.created',
				'payment.updated',
				'payment.retrying',
				'payment.cancelled',
				'payment.approved',
				'subscription.created',
				'subscription.updated',
				'subscription.paused',
				'subscription.automatically_paused',
				'subscription.resumed',
				'subscription.incomplete',
				'subscription.incomplete_expired',
				'subscription.cancelled',
				'subscription.finished',
			) as $expected
		) {
			$this->assertContains( $expected, $events, "Endpoint must subscribe to {$expected}." );
		}
	}

	public function test_only_event_names_no_duplicates(): void {
		$events = WebhookInstaller::EVENTS;

		$this->assertSame( array_values( array_unique( $events ) ), $events, 'Event list must not contain duplicates.' );
		foreach ( $events as $event ) {
			$this->assertMatchesRegularExpression( '/^(payment|subscription)\.[a-z_]+$/', $event );
		}
	}
}
