<?php
/**
 * Cart validation rules for Debi product types.
 *
 * - Subscription and Installment products cannot coexist with any other product.
 * - One-time payment products can be added freely among themselves.
 * - When the cart is not empty, the incoming product is validated against the
 *   last item added to the cart.
 *
 * @package WooCommerce_Debi
 */

if (!defined('WPINC')) {
	die;
}

class DEBIPRO_Cart {

	public static function init() {
		add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 3);
	}

	/**
	 * Prevent mixing incompatible Debi financing types in the cart.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 * @return bool
	 */
	public static function validate_add_to_cart($passed, $product_id, $quantity) {
		if (!$passed || !function_exists('WC') || !WC()->cart || empty($product_id)) {
			return $passed;
		}

		$cart_items = WC()->cart->get_cart();
		if (empty($cart_items)) {
			return $passed;
		}

		$new_type = self::get_product_type($product_id);
		if ($new_type === null) {
			return $passed;
		}

		$reference_type = self::get_last_cart_item_type($cart_items);
		if ($reference_type === null) {
			return $passed;
		}

		if (self::types_are_compatible($reference_type, $new_type)) {
			return $passed;
		}

		wc_add_notice(self::get_incompatibility_message($reference_type, $new_type), 'error');

		return false;
	}

	/**
	 * One-time products can stack; subscription and installment require an empty cart.
	 *
	 * @param DebiProFinancingType $reference Last item already in the cart.
	 * @param DebiProFinancingType $new       Product being added.
	 */
	private static function types_are_compatible(DebiProFinancingType $reference, DebiProFinancingType $new): bool {
		return $reference === DebiProFinancingType::OneTimePayment
			&& $new === DebiProFinancingType::OneTimePayment;
	}

	/**
	 * @param DebiProFinancingType $reference
	 * @param DebiProFinancingType $new
	 */
	private static function get_incompatibility_message(DebiProFinancingType $reference, DebiProFinancingType $new): string {
		if ($reference === DebiProFinancingType::Subscription || $reference === DebiProFinancingType::Installment) {
			return __('The cart already contains a subscription or installment product. You cannot add more products.', 'debi-payment-for-woocommerce');
		}

		if ($new === DebiProFinancingType::Subscription || $new === DebiProFinancingType::Installment) {
			return __('You cannot add a subscription or installment product together with other products. Please empty the cart first.', 'debi-payment-for-woocommerce');
		}

		return __('These products cannot be purchased together. Please empty the cart first.', 'debi-payment-for-woocommerce');
	}

	/**
	 * @param array<string, array<string, mixed>> $cart_items
	 */
	private static function get_last_cart_item_type(array $cart_items): ?DebiProFinancingType {
		$last_item = end($cart_items);
		if (empty($last_item['product_id'])) {
			return null;
		}

		return self::get_product_type((int) $last_item['product_id']);
	}

	/**
	 * Get the Debi financing type associated with a product.
	 *
	 * @param int $product_id
	 * @return DebiProFinancingType|null
	 */
	private static function get_product_type($product_id): ?DebiProFinancingType {
		$pf = DEBIPRO_Product_Meta::get_product_financing((int) $product_id);

		if (empty($pf) || !is_array($pf) || empty($pf['type'])) {
			return null;
		}

		return $pf['type'];
	}
}
