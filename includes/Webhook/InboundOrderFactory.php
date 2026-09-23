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
 * Creates inbound WooCommerce orders for Debi-native payments (Phase 2).
 */
final class InboundOrderFactory {

	public const PRODUCT_SKU  = 'debipro-payment';
	public const PRODUCT_NAME = 'Debi Payment';

	/**
	 * @return \WC_Order|null
	 */
	public static function create_from_payment( Payment $payment, ?Subscription $subscription = null ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return null;
		}

		$amount      = PaymentAmounts::money( isset( $payment->amount ) ? (float) $payment->amount : 0.0 );
		$payment_id  = isset( $payment->id ) ? (string) $payment->id : '';
		$description = isset( $payment->description ) ? (string) $payment->description : self::PRODUCT_NAME;
		if ( '' === $description ) {
			$description = self::PRODUCT_NAME;
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

		$product = self::ensure_product();
		if ( $product && method_exists( $order, 'add_product' ) ) {
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

	/**
	 * @return \WC_Product|null
	 */
	public static function ensure_product() {
		if ( ! function_exists( 'wc_get_product_id_by_sku' ) || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$existing_id = (int) wc_get_product_id_by_sku( self::PRODUCT_SKU );
		if ( $existing_id > 0 ) {
			$product = wc_get_product( $existing_id );
			return $product instanceof \WC_Product ? $product : null;
		}

		if ( ! class_exists( '\WC_Product_Simple' ) ) {
			return null;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( self::PRODUCT_NAME );
		$product->set_sku( self::PRODUCT_SKU );
		$product->set_status( 'private' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );
		$product->save();

		return $product;
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
