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
 * Creates the Debi subscription that backs a WooCommerce order, built on the
 * vendored Debi PHP SDK.
 *
 * Debi has no one-off "charge": payment is modelled as a subscription (the
 * installment plan), so this service creates one. The card is tokenised in the
 * browser by js.debi.pro (strict mode), so we only ever receive a payment-method
 * token id (never the PAN) and chain two idempotent API calls:
 *
 *   1. customers.create     (reused per logged-in WP user via `id_customer_debi`)
 *   2. subscriptions.create (the installment plan, billed monthly)
 *
 * Idempotency keys are derived from the blog + order/user so a retried request
 * (flaky network, double-submit) never creates a duplicate customer or a second
 * subscription for the same order.
 */
final class SubscriptionCreator {

	/**
	 * Create the subscription and return its id.
	 *
	 * @param array{
	 *     order: \WC_Order,
	 *     payment_method_token: string,
	 *     installments: int,
	 *     installment_amount: float,
	 *     description: string,
	 *     customer: array{name: string, email: string, identification_number?: string}
	 * } $args
	 * @return string Subscription id.
	 * @throws \Debi\Exception\ExceptionInterface When Debi rejects the request.
	 * @throws \RuntimeException                  On configuration / empty-result errors.
	 */
	public static function create( array $args ): string {
		$order        = $args['order'];
		$token        = trim( (string) ( $args['payment_method_token'] ?? '' ) );
		$installments = (int) ( $args['installments'] ?? 0 );
		$amount       = (float) ( $args['installment_amount'] ?? 0 );
		$description  = (string) ( $args['description'] ?? '' );
		$customer     = (array) ( $args['customer'] ?? array() );

		if ( '' === $token ) {
			throw new \RuntimeException( 'Missing payment method token.' );
		}
		if ( $installments < 1 || $amount <= 0 ) {
			throw new \RuntimeException( 'Invalid installment plan.' );
		}

		$client  = DebiClientFactory::create();
		$blog_id = (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
		$user_id = (int) $order->get_customer_id();

		$customer_id = self::resolve_customer( $client, $customer, $blog_id, $user_id, (int) $order->get_id() );
		if ( '' === $customer_id ) {
			throw new \RuntimeException( 'Could not register the Debi customer.' );
		}

		$subscription = $client->subscriptions->create(
			array(
				'amount'            => $amount,
				'description'       => $description,
				'payment_method_id' => $token,
				'interval_unit'     => 'monthly',
				'interval'          => 1,
				'day_of_month'      => self::billing_day_of_month(),
				'count'             => $installments,
				'customer_id'       => $customer_id,
			),
			array( 'idempotency_key' => sprintf( 'debipro-sub-%d-%d', $blog_id, (int) $order->get_id() ) )
		);

		$subscription_id = isset( $subscription->id ) ? (string) $subscription->id : '';
		if ( '' === $subscription_id ) {
			throw new \RuntimeException( 'Debi did not return a subscription id.' );
		}

		return $subscription_id;
	}

	/**
	 * Return the Debi customer id, reusing a stored one for logged-in users
	 * (user meta `id_customer_debi`) or creating a new customer keyed idempotently
	 * so retries never duplicate it.
	 *
	 * @param array{name: string, email: string, identification_number?: string} $customer
	 */
	private static function resolve_customer( DebiClient $client, array $customer, int $blog_id, int $user_id, int $order_id ): string {
		if ( $user_id > 0 ) {
			$existing = get_user_meta( $user_id, 'id_customer_debi', true );
			if ( is_string( $existing ) && '' !== trim( $existing ) ) {
				return trim( $existing );
			}
		}

		$params         = array(
			'name'  => (string) ( $customer['name'] ?? '' ),
			'email' => (string) ( $customer['email'] ?? '' ),
		);
		$identification = trim( (string) ( $customer['identification_number'] ?? '' ) );
		if ( '' !== $identification ) {
			$params['identification_number'] = $identification;
		}

		// Logged-in users get a stable per-user key; guests fall back to the order
		// so a double-submit still resolves to one customer.
		$idempotency_key = $user_id > 0
			? sprintf( 'debipro-cust-%d-%d', $blog_id, $user_id )
			: sprintf( 'debipro-cust-order-%d-%d', $blog_id, $order_id );

		$created     = $client->customers->create( $params, array( 'idempotency_key' => $idempotency_key ) );
		$customer_id = isset( $created->id ) ? (string) $created->id : '';

		if ( '' !== $customer_id && $user_id > 0 ) {
			update_user_meta( $user_id, 'id_customer_debi', $customer_id );
		}

		return $customer_id;
	}

	/**
	 * Day of month (1–31) on which Debi bills the subscription: the day the
	 * customer subscribes. No clamping is needed — Debi charges on the last day
	 * of any month where the chosen day doesn't exist (e.g. the 31st in February).
	 */
	private static function billing_day_of_month(): int {
		// Use the site's local date so the billing day matches the customer's
		// "today" (e.g. avoids rolling to the next day near midnight in UTC-3).
		return function_exists( 'current_time' )
			? (int) current_time( 'j' )
			: (int) gmdate( 'j' );
	}
}
