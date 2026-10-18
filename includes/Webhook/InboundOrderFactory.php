<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\Resource\Customer;
use Debi\Resource\Payment;
use Debi\Resource\Subscription;
use DebiPro\Projection\OrderMeta;
use DebiPro\Projection\PaymentAmounts;

/**
 * Creates inbound WooCommerce orders for Debi-native payments.
 *
 * Used when a Debi payment arrives with no originating WooCommerce order (e.g. a
 * payment link, or an externally-created subscription). The order is built
 * against the *real* product carried in the session metadata (`product_id`); a
 * payment with no product to attribute to is ignored by the caller — there is no
 * generic catch-all product any more.
 */
final class InboundOrderFactory {

	/**
	 * @param int $product_id The product the session metadata attributes this payment to.
	 * @return \WC_Order|null
	 */
	public static function create_from_payment( Payment $payment, ?Subscription $subscription, int $product_id ) {
		if ( ! function_exists( 'wc_create_order' ) || $product_id <= 0 ) {
			return null;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$amount      = PaymentAmounts::money( isset( $payment->amount ) ? (float) $payment->amount : 0.0 );
		$payment_id  = isset( $payment->id ) ? (string) $payment->id : '';
		$description = isset( $payment->description ) ? (string) $payment->description : '';
		if ( '' === $description ) {
			$description = (string) $product->get_name();
		}

		$email = self::email_from_payment( $payment );
		$user  = '' !== $email && function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;

		$order = wc_create_order(
			array(
				'customer_id' => ( $user instanceof \WP_User ) ? (int) $user->ID : 0,
				'created_via' => 'debipro_inbound',
			)
		);

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		if ( method_exists( $order, 'add_product' ) ) {
			$order->add_product( $product, 1 );
			foreach ( $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'set_name' ) ) {
					$item->set_name( $description );
				}
				if ( is_object( $item ) && method_exists( $item, 'set_subtotal' ) ) {
					$item->set_subtotal( (string) $amount );
				}
				if ( is_object( $item ) && method_exists( $item, 'set_total' ) ) {
					$item->set_total( (string) $amount );
				}
				if ( is_object( $item ) && method_exists( $item, 'save' ) ) {
					$item->save();
				}
			}
		}

		if ( '' !== $email && method_exists( $order, 'set_billing_email' ) ) {
			$order->set_billing_email( $email );
		}

		$name = self::name_from_payment( $payment );
		if ( '' !== $name && method_exists( $order, 'set_billing_first_name' ) ) {
			$order->set_billing_first_name( $name );
		}

		if ( method_exists( $order, 'set_payment_method' ) ) {
			$order->set_payment_method( 'debipro' );
			$order->set_payment_method_title( 'Debi' );
		}

		$subscription_id = '';
		if ( isset( $payment->subscription ) && is_string( $payment->subscription ) && '' !== $payment->subscription ) {
			$subscription_id = $payment->subscription;
		} elseif ( $subscription && isset( $subscription->id ) ) {
			$subscription_id = (string) $subscription->id;
		}

		$origin = '' !== $subscription_id
			? OrderMeta::ORIGIN_DEBI_SUBSCRIPTION_PAYMENT
			: OrderMeta::ORIGIN_DEBI_ONE_OFF_PAYMENT;

		$order->update_meta_data( OrderMeta::ORIGIN, $origin );
		$order->update_meta_data( OrderMeta::PAYMENT_ID, $payment_id );
		$order->update_meta_data( OrderMeta::PRODUCT_ID, $product_id );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, (string) $amount );
		if ( '' !== $subscription_id ) {
			$order->update_meta_data( OrderMeta::SUBSCRIPTION_ID, $subscription_id );
		}

		$customer = $payment->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->id ) ) {
			$order->update_meta_data( OrderMeta::CUSTOMER_ID, (string) $customer->id );
		}

		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals( false );
		} elseif ( method_exists( $order, 'set_total' ) ) {
			$order->set_total( (string) $amount );
		}

		$order->update_status( 'processing', sprintf( 'Created from Debi payment %s. ', $payment_id ) );
		$order->save();

		return $order;
	}

	private static function email_from_payment( Payment $payment ): string {
		$customer = $payment->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->email ) ) {
			return sanitize_email( (string) $customer->email );
		}
		return '';
	}

	private static function name_from_payment( Payment $payment ): string {
		$customer = $payment->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->name ) ) {
			return sanitize_text_field( (string) $customer->name );
		}
		return '';
	}
}
