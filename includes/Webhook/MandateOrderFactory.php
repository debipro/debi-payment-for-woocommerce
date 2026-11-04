<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\Resource\Customer;
use Debi\Resource\Mandate;
use DebiPro\Projection\OrderMeta;

/**
 * Creates the $0 "adhesion" order for a Debi mandate that arrives via webhook.
 *
 * Mirrors what the embedded checkout records synchronously in
 * {@see \DEBIPRO_Payment_Gateway::process_mandate_payment()} (mandate id, product,
 * $0 totals, completed status), but for a mandate born from a hosted link — where
 * no WooCommerce order exists yet. The product is taken from the mandate metadata
 * (`product_id`), which Debi propagates from the link; a mandate with no product to
 * attribute to is left to the caller to ignore.
 */
final class MandateOrderFactory {

	/**
	 * @param int $product_id The product the mandate metadata attributes this adhesion to.
	 * @return \WC_Order|null
	 */
	public static function create_from_mandate( Mandate $mandate, int $product_id ) {
		if ( ! function_exists( 'wc_create_order' ) || $product_id <= 0 ) {
			return null;
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$mandate_id  = isset( $mandate->id ) ? (string) $mandate->id : '';
		$email       = self::email_from_mandate( $mandate );
		$user        = '' !== $email && function_exists( 'get_user_by' ) ? get_user_by( 'email', $email ) : false;
		$description = (string) $product->get_name();

		$order = wc_create_order(
			array(
				'customer_id' => ( $user instanceof \WP_User ) ? (int) $user->ID : 0,
				'created_via' => 'debipro_inbound_mandate',
			)
		);

		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		// Adhesion moves no money: add the product and zero every line.
		if ( method_exists( $order, 'add_product' ) ) {
			$order->add_product( $product, 1 );
			foreach ( $order->get_items() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'set_name' ) ) {
					$item->set_name( $description );
				}
				if ( is_object( $item ) && method_exists( $item, 'set_subtotal' ) ) {
					$item->set_subtotal( '0' );
				}
				if ( is_object( $item ) && method_exists( $item, 'set_total' ) ) {
					$item->set_total( '0' );
				}
				if ( is_object( $item ) && method_exists( $item, 'save' ) ) {
					$item->save();
				}
			}
		}

		if ( '' !== $email && method_exists( $order, 'set_billing_email' ) ) {
			$order->set_billing_email( $email );
		}

		$name = self::name_from_mandate( $mandate );
		if ( '' !== $name && method_exists( $order, 'set_billing_first_name' ) ) {
			$order->set_billing_first_name( $name );
		}

		if ( method_exists( $order, 'set_payment_method' ) ) {
			$order->set_payment_method( 'debipro' );
			$order->set_payment_method_title( 'Debi' );
		}

		$order->update_meta_data( OrderMeta::ORIGIN, OrderMeta::ORIGIN_MANDATE );
		$order->update_meta_data( OrderMeta::MANDATE_ID, $mandate_id );
		$order->update_meta_data( OrderMeta::PRODUCT_ID, $product_id );
		$order->update_meta_data( OrderMeta::FINAL_PRICE, '0' );
		$order->update_meta_data( OrderMeta::PAYMENT_STATUS, OrderMeta::STATUS_PAID );

		$customer = $mandate->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->id ) ) {
			$order->update_meta_data( OrderMeta::CUSTOMER_ID, (string) $customer->id );
		}

		$last_four = self::card_last_four( $mandate );
		if ( '' !== $last_four ) {
			$order->update_meta_data( '_debipro_card_last_4_digits', $last_four );
		}

		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals( false );
		} elseif ( method_exists( $order, 'set_total' ) ) {
			$order->set_total( '0' );
		}

		$order->update_status( 'completed', sprintf( 'Created from Debi mandate %s; $0 adhesion. ', $mandate_id ) );
		$order->save();

		return $order;
	}

	private static function email_from_mandate( Mandate $mandate ): string {
		$customer = $mandate->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->email ) ) {
			return sanitize_email( (string) $customer->email );
		}
		return '';
	}

	private static function name_from_mandate( Mandate $mandate ): string {
		$customer = $mandate->customer ?? null;
		if ( $customer instanceof Customer && isset( $customer->name ) ) {
			return sanitize_text_field( (string) $customer->name );
		}
		return '';
	}

	private static function card_last_four( Mandate $mandate ): string {
		$pm   = $mandate->payment_method ?? null;
		$card = is_object( $pm ) && isset( $pm->card ) ? $pm->card : null;
		foreach ( array( 'last_four_digits', 'last4', 'last_four' ) as $key ) {
			if ( is_object( $card ) && isset( $card->$key ) && is_scalar( $card->$key ) ) {
				$value = (string) $card->$key;
				if ( '' !== $value ) {
					return $value;
				}
			}
		}
		return '';
	}
}
