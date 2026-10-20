<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Checkout;

use Debi\DebiClient;
use Debi\Exception\ExceptionInterface;

/**
 * Shared Debi customer + payment-method wiring for the checkout creators
 * (subscriptions, mandates).
 *
 * A fresh customer is created per order (never reused across orders): Debi
 * customer ids are scoped to the account behind the current site's secret key,
 * and any cross-order cache eventually sends an id that belongs to a different
 * account (network-global user meta, rotated keys, sandbox/live switches) and
 * Debi rejects the request. Idempotency keys are derived from blog + order so a
 * retried request never duplicates the customer or the attach.
 */
final class DebiCustomer {

	/**
	 * Create the Debi customer for an order, keyed idempotently to it.
	 *
	 * @param array{name: string, email: string, identification_number?: string} $customer
	 * @return string Customer id ('' when Debi returned none).
	 */
	public static function create_for_order( DebiClient $client, array $customer, int $blog_id, int $order_id ): string {
		$params = array(
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
	 * Best-effort link of the payment method to this order's customer.
	 *
	 * Failures are swallowed: the subscription / mandate is still created with the
	 * raw `payment_method_id`, which is enough for Debi (headless integrations
	 * often skip attach entirely).
	 */
	public static function attach_payment_method( DebiClient $client, string $token, string $customer_id, int $blog_id, int $order_id ): void {
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
