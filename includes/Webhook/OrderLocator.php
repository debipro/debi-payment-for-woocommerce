<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use DebiPro\Projection\OrderMeta;

/**
 * HPOS-safe order lookups by Debi subscription / payment ids.
 */
final class OrderLocator {

	/**
	 * Checkout plan order for a Debi subscription, if any.
	 *
	 * @return \WC_Order|null
	 */
	public static function find_checkout_by_subscription( string $subscription_id ) {
		if ( '' === $subscription_id ) {
			return null;
		}

		foreach ( self::orders_by_meta( OrderMeta::SUBSCRIPTION_ID, $subscription_id ) as $order ) {
			if ( OrderMeta::is_checkout_plan( $order ) ) {
				return $order;
			}
		}

		return null;
	}

	/**
	 * @return \WC_Order|null
	 */
	public static function find_by_payment_id( string $payment_id ) {
		if ( '' === $payment_id ) {
			return null;
		}

		$orders = self::orders_by_meta( OrderMeta::PAYMENT_ID, $payment_id );
		return $orders[0] ?? null;
	}

	/**
	 * Open checkout / inbound orders that carry a Debi subscription id (for reconcile).
	 *
	 * @return list<\WC_Order>
	 */
	public static function find_open_debipro_orders( int $limit = 100 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'    => $limit,
				'status'   => array( 'pending', 'on-hold', 'processing', 'cancelled' ),
				'meta_key' => OrderMeta::SUBSCRIPTION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'return'   => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$out = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}
		return $out;
	}

	/**
	 * @return list<\WC_Order>
	 */
	private static function orders_by_meta( string $key, string $value ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'limit'        => 20,
				'meta_key'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'   => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_compare' => '=',
				'return'       => 'objects',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$out = array();
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order ) {
				$out[] = $order;
			}
		}
		return $out;
	}
}
