<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Checkout;

use Debi\DebiClient;
use Debi\Exception\ExceptionInterface;
use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Creates the Debi subscription that backs a WooCommerce order, built on the
 * vendored Debi PHP SDK.
 *
 * Debi has no one-off "charge": payment is modelled as a subscription (the
 * installment plan), so this service creates one. The card is collected in the
 * browser by js.debi.pro (strict mode); confirmPaymentMethod() creates the
 * payment method server-side via the publishable key and returns its persistent
 * id directly — no server-side conversion step is needed. We chain two idempotent
 * API calls after that, plus a best-effort attach:
 *
 *   1. customers.create          (a fresh customer per order)
 *   2. paymentMethods.attach     (best-effort; see below)
 *   3. subscriptions.create      (the installment plan, billed monthly)
 *
 * Attach is attempted so the PM is linked to this order's customer when Debi
 * allows it. If attach fails (e.g. the PM is already attached to another
 * customer), we log and continue: subscriptions.create still receives
 * `payment_method_id` + `customer_id`, matching typical headless checkout paths.
 *
 * The customer is never reused across orders. Debi customer ids are scoped to
 * the Debi account behind the current site's secret key, so any cache that
 * outlives that scope — user meta is network-global on multisite, and keys can
 * be rotated or switched between sandbox and live — eventually sends an id that
 * belongs to a different account and Debi rejects the subscription.
 *
 * Idempotency keys are derived from the blog + order so a retried request
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
		if ( $amount <= 0 ) {
			throw new \RuntimeException( 'Invalid installment amount.' );
		}

		$client  = DebiClientFactory::create();
		$blog_id = (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );

		$order_id    = (int) $order->get_id();
		$customer_id = self::create_customer( $client, $customer, $blog_id, $order_id );

		if ( '' === $customer_id ) {
			throw new \RuntimeException( 'Could not register the Debi customer.' );
		}

		$order->update_meta_data( '_debipro_customer_id', $customer_id );

		self::attach_payment_method( $client, $token, $customer_id, $blog_id, $order_id );

		$params = array(
			'amount'            => $amount,
			'description'       => $description,
			'payment_method_id' => $token,
			'interval_unit'     => 'monthly',
			'interval'          => 1,
			'day_of_month'      => self::billing_day_of_month(),
			'customer_id'       => $customer_id,
		);

		if ( $installments > 0 ) {
			$params['count'] = $installments;
		}

		$subscription = $client->subscriptions->create(
			$params,
			array( 'idempotency_key' => sprintf( 'debipro-sub-%d-%d', $blog_id, $order_id ) )
		);

		$subscription_id = isset( $subscription->id ) ? (string) $subscription->id : '';
		if ( '' === $subscription_id ) {
			throw new \RuntimeException( 'Debi did not return a subscription id.' );
		}

		return $subscription_id;
	}

	/**
	 * Create the Debi customer for this order, keyed idempotently to the order so
	 * retries never duplicate it.
	 *
	 * @param array{name: string, email: string, identification_number?: string} $customer
	 */
	private static function create_customer( DebiClient $client, array $customer, int $blog_id, int $order_id ): string {
		$params         = array(
			'name'  => (string) ( $customer['name'] ?? '' ),
			'email' => (string) ( $customer['email'] ?? '' ),
		);
		$identification = trim( (string) ( $customer['identification_number'] ?? '' ) );
		if ( '' !== $identification ) {
			$params['identification_number'] = $identification;
		}

		$created = $client->customers->create(
			$params,
			array( 'idempotency_key' => sprintf( 'debipro-cust-order-%d-%d', $blog_id, $order_id ) )
		);

		return isset( $created->id ) ? (string) $created->id : '';
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

	/**
	 * Best-effort link of the payment method to this order's customer.
	 *
	 * Failures are swallowed: the subscription is still created with the raw
	 * `payment_method_id`, which is enough for Debi to charge (headless
	 * integrations often do the same and never call attach).
	 */
	private static function attach_payment_method( DebiClient $client, string $token, string $customer_id, int $blog_id, int $order_id ): void {
		try {
			$client->paymentMethods->attach(
				$token,
				$customer_id,
				array( 'idempotency_key' => sprintf( 'debipro-attach-%d-%d', $blog_id, $order_id ) )
			);
		} catch ( ExceptionInterface $e ) {
			$message = sprintf(
				'Debi payment method attach skipped for order %d (customer %s): %s',
				$order_id,
				$customer_id,
				$e->getMessage()
			);
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning( $message, array( 'source' => 'debipro' ) );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[debipro] ' . $message );
			}
		}
	}
}
