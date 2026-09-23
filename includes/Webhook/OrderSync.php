<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\DebiClient;
use DebiPro\Infrastructure\DebiClientFactory;
use DebiPro\Projection\OrderMeta;
use DebiPro\Projection\OrderProjector;

/**
 * Routes Debi payment webhooks onto WooCommerce orders and recomputes projection.
 *
 * Checkout orders (installment_plan) are updated in place. Debi-native payments
 * without a checkout order create/update an inbound order per payment id.
 */
final class OrderSync {

	/** Cap the per-order processed-event log so meta cannot grow unbounded. */
	private const MAX_PROCESSED_EVENTS = 50;

	/** Payment event types that trigger a projection refresh. */
	public const PAYMENT_EVENTS = array(
		'payment.created',
		'payment.updated',
		'payment.retrying',
		'payment.cancelled',
		'payment.approved', // present on some Debi API catalogues
	);

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
	 * Apply a Debi webhook event.
	 *
	 * @param string $event_id         Debi event id (idempotency).
	 * @param string $type             Debi event type.
	 * @param string $payment_id       Payment id when resource is a payment.
	 * @param string $subscription_id  Subscription id when known from the payload.
	 * @return string Result code.
	 */
	public static function handle(
		string $event_id,
		string $type,
		string $payment_id = '',
		string $subscription_id = ''
	): string {
		if ( ! in_array( $type, self::PAYMENT_EVENTS, true ) ) {
			return 'ignored';
		}
		if ( '' === $payment_id && '' === $subscription_id ) {
			return 'missing_payment_id';
		}

		try {
			$client = self::client();
		} catch ( \Throwable $e ) {
			return 'client_error';
		}

		try {
			$snapshot = DebiSnapshotFetcher::for_payment( $client, $payment_id, $subscription_id );
		} catch ( \Throwable $e ) {
			self::log( 'Debi snapshot fetch failed: ' . $e->getMessage() );
			return 'fetch_failed';
		}

		if ( '' === $subscription_id && $snapshot['payment'] && isset( $snapshot['payment']->subscription ) ) {
			$sub = $snapshot['payment']->subscription;
			if ( is_string( $sub ) ) {
				$subscription_id = $sub;
			}
		}
		if ( '' === $payment_id && $snapshot['payment'] && isset( $snapshot['payment']->id ) ) {
			$payment_id = (string) $snapshot['payment']->id;
		}

		$checkout = '' !== $subscription_id
			? OrderLocator::find_checkout_by_subscription( $subscription_id )
			: null;

		if ( $checkout ) {
			return self::apply_to_order(
				$checkout,
				$event_id,
				$type,
				$snapshot['subscription_status'],
				$snapshot['payments']
			);
		}

		// Phase 2: one order per payment.
		if ( '' === $payment_id ) {
			return 'missing_payment_id';
		}

		$inbound = OrderLocator::find_by_payment_id( $payment_id );
		if ( ! $inbound ) {
			if ( ! $snapshot['payment'] ) {
				return 'payment_not_found';
			}
			$inbound = InboundOrderFactory::create_from_payment(
				$snapshot['payment'],
				$snapshot['subscription']
			);
			if ( ! $inbound ) {
				return 'inbound_create_failed';
			}
		}

		// Inbound projection uses only this payment's current status when listing
		// by subscription would mix sibling charges onto the wrong order.
		$payments = array();
		if ( $snapshot['payment'] ) {
			$payments[] = DebiSnapshotFetcher::payment_row( $snapshot['payment'] );
		} else {
			foreach ( $snapshot['payments'] as $row ) {
				if ( ( $row['id'] ?? '' ) === $payment_id ) {
					$payments[] = $row;
					break;
				}
			}
		}

		return self::apply_to_order(
			$inbound,
			$event_id,
			$type,
			$snapshot['subscription_status'],
			$payments
		);
	}

	/**
	 * Full reproject for an existing order (reconcile / after external settlement).
	 *
	 * @return string Result code.
	 */
	public static function reproject_order( \WC_Order $order ): string {
		$subscription_id = (string) $order->get_meta( OrderMeta::SUBSCRIPTION_ID );
		$payment_id      = (string) $order->get_meta( OrderMeta::PAYMENT_ID );

		try {
			$client = self::client();
		} catch ( \Throwable $e ) {
			return 'client_error';
		}

		try {
			if ( OrderMeta::is_checkout_plan( $order ) && '' !== $subscription_id ) {
				$subscription = $client->subscriptions->retrieve( $subscription_id );
				$status       = isset( $subscription->status ) ? (string) $subscription->status : '';
				$payments     = DebiSnapshotFetcher::list_payments_for_subscription( $client, $subscription_id );
				return OrderProjector::apply( $order, $status, $payments, 'Debi reconcile. ' );
			}

			$snapshot = DebiSnapshotFetcher::for_payment( $client, $payment_id, $subscription_id );
			$payments = array();
			if ( $snapshot['payment'] ) {
				$payments[] = DebiSnapshotFetcher::payment_row( $snapshot['payment'] );
			}
			return OrderProjector::apply(
				$order,
				$snapshot['subscription_status'],
				$payments,
				'Debi reconcile. '
			);
		} catch ( \Throwable $e ) {
			self::log( 'Reproject failed for order: ' . $e->getMessage() );
			return 'fetch_failed';
		}
	}

	/**
	 * Record external settlement then reproject from Debi.
	 */
	public static function record_external_and_reproject( \WC_Order $order, float $amount, string $note = '' ): string {
		$recorded = OrderProjector::record_external_payment( $order, $amount, $note );
		if ( 'external_recorded' !== $recorded ) {
			return $recorded;
		}
		return self::reproject_order( $order );
	}

	/**
	 * @param list<array{id?: string, amount?: float|int|string, status?: string}> $payments
	 */
	private static function apply_to_order(
		\WC_Order $order,
		string $event_id,
		string $type,
		string $subscription_status,
		array $payments
	): string {
		$processed = $order->get_meta( OrderMeta::PROCESSED_EVENTS );
		$processed = is_array( $processed ) ? $processed : array();
		if ( '' !== $event_id && in_array( $event_id, $processed, true ) ) {
			return 'duplicate';
		}

		if ( '' !== $event_id ) {
			$processed[] = $event_id;
			$order->update_meta_data(
				OrderMeta::PROCESSED_EVENTS,
				array_values( array_slice( $processed, -self::MAX_PROCESSED_EVENTS ) )
			);
		}

		return OrderProjector::apply(
			$order,
			$subscription_status,
			$payments,
			sprintf( 'Debi webhook %s. ', $type )
		);
	}

	private static function client(): DebiClient {
		if ( null !== self::$client_factory ) {
			return ( self::$client_factory )();
		}
		return DebiClientFactory::create();
	}

	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'debipro' ) );
		}
	}
}
