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

		// 1. Embedded / already-correlated checkout plan (order carries the sub id).
		$checkout = '' !== $subscription_id
			? OrderLocator::find_checkout_by_subscription( $subscription_id )
			: null;

		if ( $checkout ) {
			return self::apply_to_order(
				$checkout,
				$event_id,
				$type,
				$snapshot['subscription_status'],
				self::payments_for_order( $checkout, $snapshot, $payment_id )
			);
		}

		// 2. Redirect checkout: correlate to the pending WooCommerce order via the
		// session metadata (order_id), or the session id stored before redirecting.
		$meta       = self::metadata_from( $snapshot );
		$session_id = self::session_id_from( $snapshot );
		$redirect   = self::locate_redirect_order( $meta['order_id'], $session_id );
		if ( $redirect ) {
			self::persist_ids( $redirect, $subscription_id, $payment_id, $session_id );
			return self::apply_to_order(
				$redirect,
				$event_id,
				$type,
				$snapshot['subscription_status'],
				self::payments_for_order( $redirect, $snapshot, $payment_id )
			);
		}

		// 3. Inbound order already created for this payment.
		if ( '' === $payment_id ) {
			return 'missing_payment_id';
		}

		$inbound = OrderLocator::find_by_payment_id( $payment_id );
		if ( $inbound ) {
			return self::apply_to_order(
				$inbound,
				$event_id,
				$type,
				$snapshot['subscription_status'],
				self::payments_for_order( $inbound, $snapshot, $payment_id )
			);
		}

		// 4. Create an inbound order against the real product in the metadata.
		if ( ! $snapshot['payment'] ) {
			return 'payment_not_found';
		}
		if ( $meta['product_id'] > 0 ) {
			$created = InboundOrderFactory::create_from_payment(
				$snapshot['payment'],
				$snapshot['subscription'],
				$meta['product_id']
			);
			if ( ! $created ) {
				return 'inbound_create_failed';
			}
			return self::apply_to_order(
				$created,
				$event_id,
				$type,
				$snapshot['subscription_status'],
				self::payments_for_order( $created, $snapshot, $payment_id )
			);
		}

		// 5. No product to attribute and no matching order: ignore. There is no
		// generic catch-all product any more — a true orphan stays Debi-only.
		self::log( sprintf( 'Debi payment %s has no product metadata and no matching order; ignoring.', $payment_id ) );
		return 'orphan_ignored';
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

	/**
	 * Read order_id / product_id from the session metadata, which Debi propagates
	 * onto the produced subscription and payment.
	 *
	 * @param array{subscription: ?\Debi\Resource\Subscription, payment: ?\Debi\Resource\Payment} $snapshot
	 * @return array{order_id: int, product_id: int}
	 */
	private static function metadata_from( array $snapshot ): array {
		$out = array(
			'order_id'   => 0,
			'product_id' => 0,
		);
		foreach ( array( $snapshot['subscription'] ?? null, $snapshot['payment'] ?? null ) as $resource ) {
			if ( null === $resource || ! isset( $resource->metadata ) ) {
				continue;
			}
			$md         = $resource->metadata;
			$order_id   = self::md_int( $md, 'order_id' );
			$product_id = self::md_int( $md, 'product_id' );
			if ( $order_id > 0 && 0 === $out['order_id'] ) {
				$out['order_id'] = $order_id;
			}
			if ( $product_id > 0 && 0 === $out['product_id'] ) {
				$out['product_id'] = $product_id;
			}
		}
		return $out;
	}

	/**
	 * @param mixed $metadata DebiObject|array|null
	 */
	private static function md_int( $metadata, string $key ): int {
		if ( is_object( $metadata ) && isset( $metadata->$key ) ) {
			return (int) $metadata->$key;
		}
		if ( is_array( $metadata ) && isset( $metadata[ $key ] ) ) {
			return (int) $metadata[ $key ];
		}
		return 0;
	}

	/**
	 * @param array{payment: ?\Debi\Resource\Payment} $snapshot
	 */
	private static function session_id_from( array $snapshot ): string {
		$payment = $snapshot['payment'] ?? null;
		if ( $payment && isset( $payment->session ) && is_string( $payment->session ) ) {
			return (string) $payment->session;
		}
		return '';
	}

	/**
	 * @return \WC_Order|null
	 */
	private static function locate_redirect_order( int $order_id, string $session_id ) {
		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && self::is_debipro_order( $order ) ) {
				return $order;
			}
		}
		return OrderLocator::find_by_session_id( $session_id );
	}

	private static function is_debipro_order( \WC_Order $order ): bool {
		if ( '' !== (string) $order->get_meta( OrderMeta::SESSION_ID ) ) {
			return true;
		}
		return 'debipro' === (string) $order->get_payment_method();
	}

	/**
	 * Persist the Debi ids learned from the first webhook so later events correlate
	 * directly (checkout plans by subscription id, one-offs by payment id).
	 */
	private static function persist_ids( \WC_Order $order, string $subscription_id, string $payment_id, string $session_id ): void {
		$changed = false;
		if ( '' !== $subscription_id && '' === (string) $order->get_meta( OrderMeta::SUBSCRIPTION_ID ) ) {
			$order->update_meta_data( OrderMeta::SUBSCRIPTION_ID, $subscription_id );
			$changed = true;
		}
		if ( '' !== $payment_id && '' === (string) $order->get_meta( OrderMeta::PAYMENT_ID ) ) {
			$order->update_meta_data( OrderMeta::PAYMENT_ID, $payment_id );
			$changed = true;
		}
		if ( '' !== $session_id && '' === (string) $order->get_meta( OrderMeta::SESSION_ID ) ) {
			$order->update_meta_data( OrderMeta::SESSION_ID, $session_id );
			$changed = true;
		}
		if ( $changed ) {
			$order->save();
		}
	}

	/**
	 * Payments to project onto an order: all subscription payments for a checkout
	 * plan, just this one for a one-off (avoids mixing sibling charges).
	 *
	 * @param array{payments: list<array{id?: string, amount?: float|int|string, status?: string}>, payment: ?\Debi\Resource\Payment} $snapshot
	 * @return list<array{id?: string, amount?: float|int|string, status?: string}>
	 */
	private static function payments_for_order( \WC_Order $order, array $snapshot, string $payment_id ): array {
		if ( OrderMeta::is_checkout_plan( $order ) ) {
			return $snapshot['payments'];
		}
		if ( $snapshot['payment'] ) {
			return array( DebiSnapshotFetcher::payment_row( $snapshot['payment'] ) );
		}
		foreach ( $snapshot['payments'] as $row ) {
			if ( ( $row['id'] ?? '' ) === $payment_id ) {
				return array( $row );
			}
		}
		return array();
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
