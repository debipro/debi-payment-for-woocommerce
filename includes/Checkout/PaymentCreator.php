<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Checkout;

use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Creates a single Debi payment (one-off charge) for a WooCommerce order.
 *
 * This is Debi's native primitive for a one-time charge: `POST /v1/payments`.
 * Use it for `payment` products — a plain, single charge. It is deliberately
 * NOT used for installments or memberships: those are genuine subscriptions
 * (N scheduled debits / open-ended recurring) and go through
 * {@see SubscriptionCreator}.
 *
 * The card is collected in the browser by js.debi.pro (strict mode);
 * confirmPaymentMethod() returns a persistent payment-method id. We then, like
 * the subscription path:
 *
 *   1. customers.create       (a fresh customer per order)
 *   2. paymentMethods.attach  (best-effort; the charge still carries the pm id)
 *   3. payments.create        (the one-off charge)
 *
 * Idempotency keys are derived from the blog + order so a retried request never
 * creates a duplicate customer or a second charge for the same order.
 */
final class PaymentCreator {

	/**
	 * Create the payment and return the Debi resource.
	 *
	 * @param array{
	 *     order: \WC_Order,
	 *     payment_method_token: string,
	 *     amount: float,
	 *     description: string,
	 *     metadata?: array<string, string>,
	 *     customer: array{name: string, email: string, identification_number?: string}
	 * } $args
	 * @return \Debi\Resource\Payment The created payment.
	 * @throws \Debi\Exception\ExceptionInterface When Debi rejects the request.
	 * @throws \RuntimeException                  On configuration / empty-result errors.
	 */
	public static function create( array $args ): \Debi\Resource\Payment {
		$order       = $args['order'];
		$token       = trim( (string) ( $args['payment_method_token'] ?? '' ) );
		$amount      = (float) ( $args['amount'] ?? 0 );
		$description = (string) ( $args['description'] ?? '' );
		$customer    = (array) ( $args['customer'] ?? array() );
		$metadata    = (array) ( $args['metadata'] ?? array() );

		if ( '' === $token ) {
			throw new \RuntimeException( 'Missing payment method token.' );
		}
		if ( $amount <= 0 ) {
			throw new \RuntimeException( 'Invalid payment amount.' );
		}

		$client   = DebiClientFactory::create();
		$blog_id  = (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
		$order_id = (int) $order->get_id();

		$customer_id = DebiCustomer::create_for_order( $client, $customer, $blog_id, $order_id );
		if ( '' === $customer_id ) {
			throw new \RuntimeException( 'Could not register the Debi customer.' );
		}

		$order->update_meta_data( '_debipro_customer_id', $customer_id );

		DebiCustomer::attach_payment_method( $client, $token, $customer_id, $blog_id, $order_id );

		$payment = $client->payments->create(
			self::build_params( $amount, $description, $token, $customer_id, $metadata ),
			array( 'idempotency_key' => sprintf( 'debipro-pay-%d-%d', $blog_id, $order_id ) )
		);

		$payment_id = isset( $payment->id ) ? (string) $payment->id : '';
		if ( '' === $payment_id ) {
			throw new \RuntimeException( 'Debi did not return a payment id.' );
		}

		return $payment;
	}

	/**
	 * Assemble the `payments.create` body. Pure (no network) so it can be tested.
	 *
	 * Currency is intentionally omitted: Debi defaults it to the account's default
	 * currency, matching {@see SubscriptionCreator}. `customer_id` is required by
	 * the API unless a `mandate_id` is sent, which this one-off flow never does.
	 *
	 * @param array<string, string> $metadata
	 * @return array<string, mixed>
	 */
	public static function build_params( float $amount, string $description, string $payment_method_id, string $customer_id, array $metadata ): array {
		$params = array(
			'amount'            => $amount,
			'description'       => $description,
			'payment_method_id' => $payment_method_id,
			'customer_id'       => $customer_id,
		);
		if ( array() !== $metadata ) {
			$params['metadata'] = $metadata;
		}
		return $params;
	}
}
