<?php

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

		$result = OrderSync::handle(
			(string) ( $event->id ?? '' ),
			(string) ( $event->type ?? '' ),
			self::subscription_id_from( $event )
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
	 * Extract the subscription id an event refers to. For subscription.* events
	 * Debi sets `resource_id` to the subscription id; we fall back to the nested
	 * `data.object.id` for resilience.
	 */
	private static function subscription_id_from( Event $event ): string {
		$resource_id = $event->resource_id ?? null;
		if ( is_string( $resource_id ) && '' !== $resource_id ) {
			return $resource_id;
		}

		$data = $event->data ?? null;
		if ( $data instanceof \Debi\DebiObject ) {
			$object = $data->object ?? null;
			if ( $object instanceof \Debi\DebiObject ) {
				$id = $object->id ?? null;
				if ( is_string( $id ) ) {
					return $id;
				}
			}
		}

		return '';
	}
}
