<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Checkout;

use Debi\DebiClient;
use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Creates a Debi mandate (authorization to debit a payment method) for an order,
 * without charging now.
 *
 * The card is captured in the browser by js.debi.pro (strict mode); this service
 * registers the customer, best-effort attaches the payment method, and creates
 * the mandate with `metadata` (blog_id, product_id, wp_user_id) so any future
 * charge Debi propagates it onto can be reconciled. The merchant collects money
 * later by charging the mandate out-of-band; adhesion itself moves no money, so
 * the WooCommerce order is completed at $0 (see the gateway).
 */
final class MandateCreator {

	/** @var callable():DebiClient|null */
	private static $client_factory = null;

	/**
	 * Override Debi client construction (unit tests).
	 *
	 * @param callable():DebiClient|null $factory
	 */
	public static function set_client_factory( $factory ): void {
		self::$client_factory = $factory;
	}

	/**
	 * Create the mandate and return its id.
	 *
	 * @param array{
	 *     order: \WC_Order,
	 *     payment_method_token: string,
	 *     description: string,
	 *     metadata?: array<string, string>,
	 *     customer: array{name: string, email: string, identification_number?: string}
	 * } $args
	 * @return string Mandate id.
	 * @throws \Debi\Exception\ExceptionInterface When Debi rejects the request.
	 * @throws \RuntimeException                  On configuration / empty-result errors.
	 */
	public static function create( array $args ): string {
		$order = $args['order'];
		$token = trim( (string) ( $args['payment_method_token'] ?? '' ) );

		if ( '' === $token ) {
			throw new \RuntimeException( 'Missing payment method token.' );
		}

		$client   = self::client();
		$blog_id  = (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
		$order_id = (int) $order->get_id();

		$customer_id = DebiCustomer::create_for_order( $client, (array) ( $args['customer'] ?? array() ), $blog_id, $order_id );
		if ( '' === $customer_id ) {
			throw new \RuntimeException( 'Could not register the Debi customer.' );
		}

		$order->update_meta_data( '_debipro_customer_id', $customer_id );

		DebiCustomer::attach_payment_method( $client, $token, $customer_id, $blog_id, $order_id );

		$mandate = $client->mandates->create(
			self::build_params( $args, $customer_id ),
			array( 'idempotency_key' => sprintf( 'debipro-mandate-%d-%d', $blog_id, $order_id ) )
		);

		$mandate_id = isset( $mandate->id ) ? (string) $mandate->id : '';
		if ( '' === $mandate_id ) {
			throw new \RuntimeException( 'Debi did not return a mandate id.' );
		}

		return $mandate_id;
	}

	/**
	 * Assemble the `mandates.create` params. Pure (no network) so it is unit-testable.
	 *
	 * @param array{
	 *     payment_method_token?: string,
	 *     description?: string,
	 *     metadata?: array<string, string>
	 * } $args
	 * @return array<string, mixed>
	 */
	public static function build_params( array $args, string $customer_id ): array {
		$params = array(
			'payment_method_id' => trim( (string) ( $args['payment_method_token'] ?? '' ) ),
			'customer_id'       => $customer_id,
			'description'       => (string) ( $args['description'] ?? '' ),
		);

		$metadata = (array) ( $args['metadata'] ?? array() );
		if ( array() !== $metadata ) {
			$params['metadata'] = $metadata;
		}

		return $params;
	}

	private static function client(): DebiClient {
		if ( null !== self::$client_factory ) {
			return ( self::$client_factory )();
		}
		return DebiClientFactory::create();
	}
}
