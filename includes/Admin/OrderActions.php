<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Admin;

use DebiPro\Projection\OrderMeta;
use DebiPro\Webhook\OrderSync;

/**
 * Order admin actions for Debi projection (external settlement).
 */
final class OrderActions {

	public static function init(): void {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'register_action' ) );
		add_action( 'woocommerce_order_action_debipro_external_settlement', array( __CLASS__, 'handle_external_settlement' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'render_projection_metabox' ) );
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function register_action( array $actions ): array {
		$actions['debipro_external_settlement'] = __( 'Debi: record external settlement (full remaining)', 'debi-payment-for-woocommerce' );
		return $actions;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function handle_external_settlement( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$remaining = (float) $order->get_meta( OrderMeta::AMOUNT_REMAINING );
		if ( $remaining <= 0 ) {
			$remaining = max(
				0.0,
				(float) $order->get_meta( OrderMeta::FINAL_PRICE ) - (float) $order->get_meta( OrderMeta::AMOUNT_PAID )
			);
		}
		if ( $remaining <= 0 && method_exists( $order, 'get_total' ) ) {
			$remaining = (float) $order->get_total() - (float) $order->get_meta( OrderMeta::AMOUNT_PAID );
		}
		if ( $remaining <= 0 ) {
			$order->add_order_note( __( 'Debi external settlement skipped: nothing remaining.', 'debi-payment-for-woocommerce' ) );
			$order->save();
			return;
		}

		$result = OrderSync::record_external_and_reproject( $order, $remaining );
		$order->add_order_note(
			sprintf(
				/* translators: 1: amount, 2: result code */
				__( 'Debi external settlement of %1$s applied (%2$s).', 'debi-payment-for-woocommerce' ),
				(string) $remaining,
				$result
			)
		);
		$order->save();
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function render_projection_metabox( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$status = (string) $order->get_meta( OrderMeta::PAYMENT_STATUS );
		if ( '' === $status && '' === (string) $order->get_meta( OrderMeta::SUBSCRIPTION_ID ) ) {
			return;
		}

		echo '<div class="debipro-projection" style="margin-top:1em">';
		echo '<h3>' . esc_html__( 'Debi payment projection', 'debi-payment-for-woocommerce' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Status', 'debi-payment-for-woocommerce' ) . ':</strong> ' . esc_html( $status ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Paid', 'debi-payment-for-woocommerce' ) . ':</strong> ' . esc_html( (string) $order->get_meta( OrderMeta::AMOUNT_PAID ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Overdue', 'debi-payment-for-woocommerce' ) . ':</strong> ' . esc_html( (string) $order->get_meta( OrderMeta::AMOUNT_OVERDUE ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Remaining', 'debi-payment-for-woocommerce' ) . ':</strong> ' . esc_html( (string) $order->get_meta( OrderMeta::AMOUNT_REMAINING ) ) . '</p>';
		$origin = (string) $order->get_meta( OrderMeta::ORIGIN );
		if ( '' !== $origin ) {
			echo '<p><strong>' . esc_html__( 'Origin', 'debi-payment-for-woocommerce' ) . ':</strong> ' . esc_html( $origin ) . '</p>';
		}
		echo '</div>';
	}
}
