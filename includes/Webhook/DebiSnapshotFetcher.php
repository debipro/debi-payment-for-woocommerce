<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\DebiClient;
use Debi\Resource\Payment;
use Debi\Resource\Subscription;

/**
 * Fetches subscription + payment snapshots from Debi for projection.
 *
 * Swappable in unit tests via {@see OrderSync::set_client_factory()}.
 */
final class DebiSnapshotFetcher {

	/**
	 * @return array{
	 *     subscription_status: string,
	 *     payments: list<array{id: string, amount: float, status: string}>,
	 *     subscription: ?Subscription,
	 *     payment: ?Payment
	 * }
	 */
	public static function for_payment( DebiClient $client, string $payment_id, string $subscription_id = '' ): array {
		$payment = null;
		if ( '' !== $payment_id ) {
			$payment = $client->payments->retrieve( $payment_id );
			if ( '' === $subscription_id && isset( $payment->subscription ) && is_string( $payment->subscription ) ) {
				$subscription_id = $payment->subscription;
			}
		}

		$subscription_status = '';
		$subscription        = null;
		$payments            = array();

		if ( '' !== $subscription_id ) {
			$subscription        = $client->subscriptions->retrieve( $subscription_id );
			$subscription_status = isset( $subscription->status ) ? (string) $subscription->status : '';
			$payments            = self::list_payments_for_subscription( $client, $subscription_id );
		} elseif ( $payment instanceof Payment ) {
			$payments = array( self::payment_row( $payment ) );
		}

		return array(
			'subscription_status' => $subscription_status,
			'payments'            => $payments,
			'subscription'        => $subscription,
			'payment'             => $payment,
		);
	}

	/**
	 * @return list<array{id: string, amount: float, status: string}>
	 */
	public static function list_payments_for_subscription( DebiClient $client, string $subscription_id ): array {
		$rows = array();
		foreach (
			$client->payments->all(
				array(
					'subscription_id' => $subscription_id,
					'limit'           => 100,
				)
			)->autoPagingIterator() as $payment
		) {
			if ( $payment instanceof Payment ) {
				$rows[] = self::payment_row( $payment );
			}
		}
		return $rows;
	}

	/**
	 * @return array{id: string, amount: float, status: string}
	 */
	public static function payment_row( Payment $payment ): array {
		return array(
			'id'     => isset( $payment->id ) ? (string) $payment->id : '',
			'amount' => isset( $payment->amount ) ? (float) $payment->amount : 0.0,
			'status' => isset( $payment->status ) ? (string) $payment->status : '',
		);
	}
}
