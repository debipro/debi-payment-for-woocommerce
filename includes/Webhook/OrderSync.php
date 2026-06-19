<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

/**
 * Maps Debi subscription lifecycle events onto WooCommerce order statuses.
 *
 * The checkout stores the Debi subscription id on the order (`_debipro_subscription_id`),
 * so each incoming event is resolved back to its order and the status is moved:
 *
 *   - subscription.finished  → completed (every installment was collected)
 *   - subscription.cancelled → cancelled (rejected payment or a dashboard action)
 *
 * Processing is idempotent: webhooks can be delivered more than once or out of
 * order. We record handled event ids on the order and treat a transition into
 * the current status as a no-op, so replays never double-apply.
 *
 * Inputs are primitives (not the SDK Event) to keep the mapping unit-testable
 * without constructing an HTTP request or SDK object.
 */
final class OrderSync {

	private const STATUS_MAP = array(
		'subscription.finished'  => 'completed',
		'subscription.cancelled' => 'cancelled',
	);

	/** Cap the per-order processed-event log so meta cannot grow unbounded. */
	private const MAX_PROCESSED_EVENTS = 50;

	/**
	 * Apply the event to its order.
	 *
	 * @param string $event_id        Debi event id (for idempotency).
	 * @param string $type            Debi event type.
	 * @param string $subscription_id Subscription id the event refers to.
	 * @return string A short result code describing what happened.
	 */
	public static function handle( string $event_id, string $type, string $subscription_id ): string {
		if ( ! isset( self::STATUS_MAP[ $type ] ) ) {
			return 'ignored';
		}
		if ( '' === $subscription_id ) {
			return 'missing_subscription_id';
		}

		$order = self::find_order_by_subscription( $subscription_id );
		if ( ! $order ) {
			return 'order_not_found';
		}

		$processed = $order->get_meta( '_debipro_processed_events' );
		$processed = is_array( $processed ) ? $processed : array();
		if ( '' !== $event_id && in_array( $event_id, $processed, true ) ) {
			return 'duplicate';
		}

		if ( '' !== $event_id ) {
			$processed[] = $event_id;
			$order->update_meta_data( '_debipro_processed_events', array_values( array_slice( $processed, -self::MAX_PROCESSED_EVENTS ) ) );
		}

		$target = self::STATUS_MAP[ $type ];

		if ( $order->has_status( $target ) ) {
			$order->save();
			return 'noop_already_' . $target;
		}

		$order->update_status(
			$target,
			sprintf( 'Debi webhook %s (subscription %s). ', $type, $subscription_id )
		);
		$order->save();

		return 'updated_' . $target;
	}

	/**
	 * Find the order carrying a given Debi subscription id. Routes through the
	 * active order data store (HPOS-safe), matching how the checkout stores it.
	 *
	 * @return \WC_Order|null
	 */
	private static function find_order_by_subscription( string $subscription_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'        => 1,
				'meta_key'     => '_debipro_subscription_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $subscription_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
				'return'       => 'objects',
			)
		);

		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return null;
		}

		$order = $orders[0];
		return $order instanceof \WC_Order ? $order : null;
	}
}
