<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\Exception\SignatureVerificationException;
use Debi\Resource\Event;
use Debi\Webhook;

/**
 * REST endpoint that receives Debi webhook events for this site.
 *
 * Route: POST /wp-json/debipro/v1/webhook
 *
 * The endpoint is public (Debi calls it server-to-server), so its only gate is
 * the HMAC-SHA256 signature in the `Debi-Signature` header, verified by the SDK
 * against this site's stored endpoint secret. The raw request body must be
 * passed unmodified to the verifier — re-encoding it would break the signature.
 */
final class WebhookController {

	private const GATEWAY_OPTION = 'woocommerce_debipro_settings';

	/**
	 * Register the REST route. Hook on `rest_api_init`.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			'debipro/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify and process a webhook delivery.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$secret = self::webhook_secret();
		if ( '' === $secret ) {
			return new \WP_REST_Response( array( 'error' => 'webhook_not_configured' ), 503 );
		}

		$payload   = $request->get_body();
		$signature = (string) $request->get_header( 'Debi-Signature' );

		try {
			$event = Webhook::constructEvent( $payload, $signature, $secret );
		} catch ( SignatureVerificationException $e ) {
			return new \WP_REST_Response( array( 'error' => 'invalid_signature' ), 400 );
		}

		$ids = self::ids_from( $event );

		$result = OrderSync::handle(
			(string) ( $event->id ?? '' ),
			(string) ( $event->type ?? '' ),
			$ids['payment_id'],
			$ids['subscription_id']
		);

		// Always acknowledge once the signature checks out, so Debi stops
		// retrying even when the event is one we intentionally ignore.
		return new \WP_REST_Response(
			array(
				'received' => true,
				'result'   => $result,
			),
			200
		);
	}

	/**
	 * The endpoint signing secret stored in the gateway settings.
	 */
	private static function webhook_secret(): string {
		$settings = get_option( self::GATEWAY_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return trim( (string) ( $settings['webhook_secret'] ?? '' ) );
	}

	/**
	 * Extract payment and subscription ids from a Debi event.
	 *
	 * @return array{payment_id: string, subscription_id: string}
	 */
	public static function ids_from( Event $event ): array {
		$payment_id      = '';
		$subscription_id = '';
		$type            = (string) ( $event->type ?? '' );
		$resource        = (string) ( $event->resource ?? '' );
		$resource_id     = is_string( $event->resource_id ?? null ) ? (string) $event->resource_id : '';

		if ( 0 === strpos( $type, 'payment.' ) || 'payment' === $resource ) {
			$payment_id = $resource_id;
		}
		if ( 0 === strpos( $type, 'subscription.' ) || 'subscription' === $resource ) {
			$subscription_id = $resource_id;
		}

		$data = $event->data ?? null;
		if ( $data instanceof \Debi\DebiObject ) {
			$object = $data->object ?? null;
			if ( $object instanceof \Debi\DebiObject ) {
				$object_name = isset( $object->object ) ? (string) $object->object : '';
				$id          = isset( $object->id ) ? (string) $object->id : '';
				if ( 'payment' === $object_name || ( '' === $object_name && '' === $payment_id && 0 === strpos( $type, 'payment.' ) ) ) {
					if ( '' !== $id ) {
						$payment_id = $id;
					}
					$sub = $object->subscription ?? null;
					if ( is_string( $sub ) && '' !== $sub ) {
						$subscription_id = $sub;
					}
				}
				if ( 'subscription' === $object_name && '' !== $id ) {
					$subscription_id = $id;
				}
			}
		}

		return array(
			'payment_id'      => $payment_id,
			'subscription_id' => $subscription_id,
		);
	}
}
