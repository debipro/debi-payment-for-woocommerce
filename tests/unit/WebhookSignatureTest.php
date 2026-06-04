<?php
/**
 * Unit tests for webhook signature verification (vendored Debi SDK).
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

namespace Tucuota\DebiPaymentForWooCommerce\Tests\Unit;

use Debi\Exception\SignatureVerificationException;
use Debi\Webhook;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Debi\Webhook
 */
final class WebhookSignatureTest extends TestCase {

	private const SECRET = 'whsec_test_secret';

	private function payload(): string {
		return (string) json_encode(
			array(
				'id'          => 'EV123',
				'type'        => 'subscription.finished',
				'resource'    => 'subscription',
				'resource_id' => 'SUB123',
				'data'        => array(
					'object' => array(
						'id'     => 'SUB123',
						'object' => 'subscription',
					),
				),
			)
		);
	}

	private function sign( string $payload, int $timestamp, string $secret ): string {
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		return 't=' . $timestamp . ',v1=' . $signature;
	}

	public function test_valid_signature_constructs_event(): void {
		$payload = $this->payload();
		$header  = $this->sign( $payload, time(), self::SECRET );

		$event = Webhook::constructEvent( $payload, $header, self::SECRET );

		$this->assertSame( 'EV123', $event->id );
		$this->assertSame( 'subscription.finished', $event->type );
		$this->assertSame( 'SUB123', $event->resource_id );
	}

	public function test_tampered_signature_is_rejected(): void {
		$payload = $this->payload();
		$header  = 't=' . time() . ',v1=deadbeefdeadbeef';

		$this->expectException( SignatureVerificationException::class );
		Webhook::constructEvent( $payload, $header, self::SECRET );
	}

	public function test_stale_timestamp_is_rejected(): void {
		$payload = $this->payload();
		$header  = $this->sign( $payload, time() - 4000, self::SECRET );

		$this->expectException( SignatureVerificationException::class );
		Webhook::constructEvent( $payload, $header, self::SECRET );
	}
}
