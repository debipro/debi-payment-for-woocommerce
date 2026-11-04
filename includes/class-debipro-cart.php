<?php
/**
 * Cart validation rules for Debi session types.
 *
 * - Payment products can be added freely among themselves.
 * - Installment, subscription and mandate products each require an empty cart
 *   (they open their own dedicated Debi session).
 * - When the cart is not empty, the incoming product is validated against the
 *   last item added to the cart.
 *
 * @package WooCommerce_Debi
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

class DEBIPRO_Cart {

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'zero_mandate_line_prices' ), 20 );
		// A $0 mandate must still run the gateway (card capture creates the Debi
		// mandate). Left alone, WooCommerce treats a $0 total as a "free order",
		// skips the payment step, and jumps straight to "order received" without
		// ever asking for a card. Force payment-required whenever a mandate is in
		// play, on both the cart (classic checkout dispatch) and order (Store API
		// / blocks checkout) sides.
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'mandate_cart_needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'mandate_order_needs_payment' ), 10, 2 );
		// Present a mandate line as an authorization, not a discounted purchase:
		// replace the price/subtotal with a clear "authorization only" label and
		// add an explanatory note (shown in both classic and blocks checkout).
		add_filter( 'woocommerce_cart_item_price', array( __CLASS__, 'mandate_cart_item_price' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_subtotal', array( __CLASS__, 'mandate_cart_item_subtotal' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'mandate_cart_item_data' ), 10, 2 );
	}

	/**
	 * A mandate (adhesion) authorizes future debits but charges nothing now, so
	 * its cart line — and therefore the order total shown at checkout — must be
	 * $0. The product's configured price is informational only; the mandate sent
	 * to Debi carries no amount (see {@see \DebiPro\Checkout\MandateCreator}). A
	 * mandate always sits alone in the cart (see validate_add_to_cart), so this
	 * zeroes the whole checkout total.
	 *
	 * The regular/sale prices on the in-cart product clone are zeroed too so the
	 * line does not render as a "discounted" purchase (a $0 active price against a
	 * non-zero regular price shows a struck-through sale, which misleads the buyer
	 * into thinking they are getting a deal rather than leaving a payment method).
	 * This only mutates the runtime cart clone, never the stored product price.
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	public static function zero_mandate_line_prices( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['product_id'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			if ( DebiProSessionType::Mandate !== self::get_product_type( (int) $cart_item['product_id'] ) ) {
				continue;
			}
			$product = $cart_item['data'];
			if ( ! is_object( $product ) ) {
				continue;
			}
			if ( method_exists( $product, 'set_sale_price' ) ) {
				$product->set_sale_price( '' );
			}
			if ( method_exists( $product, 'set_regular_price' ) ) {
				$product->set_regular_price( 0 );
			}
			if ( method_exists( $product, 'set_price' ) ) {
				$product->set_price( 0 );
			}
		}
	}

	/**
	 * Whether a cart item is a mandate (adhesion) line.
	 *
	 * @param array<string, mixed> $cart_item
	 */
	private static function is_mandate_cart_item( $cart_item ): bool {
		if ( empty( $cart_item['product_id'] ) ) {
			return false;
		}
		return DebiProSessionType::Mandate === self::get_product_type( (int) $cart_item['product_id'] );
	}

	/**
	 * Replace a mandate line's unit price with a clear "authorization only" label
	 * so a $0 figure never reads as a discounted purchase.
	 *
	 * @param string               $price_html
	 * @param array<string, mixed> $cart_item
	 * @return string
	 */
	public static function mandate_cart_item_price( $price_html, $cart_item ) {
		return self::is_mandate_cart_item( $cart_item )
			? esc_html__( 'Authorization only — no charge', 'debi-payment-for-woocommerce' )
			: $price_html;
	}

	/**
	 * Same treatment for the line subtotal shown in the cart / order review.
	 *
	 * @param string               $subtotal_html
	 * @param array<string, mixed> $cart_item
	 * @return string
	 */
	public static function mandate_cart_item_subtotal( $subtotal_html, $cart_item ) {
		return self::is_mandate_cart_item( $cart_item )
			? esc_html__( 'Authorization only — no charge', 'debi-payment-for-woocommerce' )
			: $subtotal_html;
	}

	/**
	 * Add an explanatory note under a mandate line, visible in both the classic
	 * and blocks checkout (the Store API exposes this item data too).
	 *
	 * @param array<int, array<string, mixed>> $item_data
	 * @param array<string, mixed>             $cart_item
	 * @return array<int, array<string, mixed>>
	 */
	public static function mandate_cart_item_data( $item_data, $cart_item ) {
		if ( ! self::is_mandate_cart_item( $cart_item ) ) {
			return $item_data;
		}
		$item_data[] = array(
			'key'     => esc_html__( 'Adhesion', 'debi-payment-for-woocommerce' ),
			'value'   => esc_html__( 'We only register your payment method for future debits. No payment is taken now.', 'debi-payment-for-woocommerce' ),
			'display' => '',
		);
		return $item_data;
	}

	/**
	 * Force the classic checkout to require payment when the cart holds a mandate,
	 * even though its total is $0. Without this, WooCommerce short-circuits the
	 * payment step for a zero total and the gateway never captures the card.
	 *
	 * @param bool     $needs_payment
	 * @param \WC_Cart $cart
	 * @return bool
	 */
	public static function mandate_cart_needs_payment( $needs_payment, $cart ) {
		if ( $needs_payment ) {
			return $needs_payment;
		}
		if ( ! $cart instanceof \WC_Cart ) {
			return $needs_payment;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['product_id'] ) ) {
				continue;
			}
			if ( DebiProSessionType::Mandate === self::get_product_type( (int) $cart_item['product_id'] ) ) {
				return true;
			}
		}
		return $needs_payment;
	}

	/**
	 * Order-side counterpart for the Store API / blocks checkout, which gates the
	 * payment on {@see \WC_Order::needs_payment()} rather than the cart.
	 *
	 * @param bool      $needs_payment
	 * @param \WC_Order $order
	 * @return bool
	 */
	public static function mandate_order_needs_payment( $needs_payment, $order ) {
		if ( $needs_payment ) {
			return $needs_payment;
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_items' ) ) {
			return $needs_payment;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
				continue;
			}
			if ( DebiProSessionType::Mandate === self::get_product_type( (int) $item->get_product_id() ) ) {
				return true;
			}
		}
		return $needs_payment;
	}

	/**
	 * Prevent mixing incompatible Debi financing types in the cart.
	 *
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity Required by the WooCommerce filter; unused in this validation.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart || empty( $product_id ) ) {
			return $passed;
		}

		$cart_items = WC()->cart->get_cart();
		if ( empty( $cart_items ) ) {
			return $passed;
		}

		$new_type = self::get_product_type( $product_id );
		if ( null === $new_type ) {
			return $passed;
		}

		$reference_type = self::get_last_cart_item_type( $cart_items );
		if ( null === $reference_type ) {
			return $passed;
		}

		if ( self::types_are_compatible( $reference_type, $new_type ) ) {
			return $passed;
		}

		wc_add_notice( self::get_incompatibility_message( $reference_type, $new_type ), 'error' );

		return false;
	}

	/**
	 * Payment products can stack; every other session type requires an empty cart.
	 *
	 * @param DebiProSessionType $reference Last item already in the cart.
	 * @param DebiProSessionType $incoming Product being added.
	 */
	private static function types_are_compatible( DebiProSessionType $reference, DebiProSessionType $incoming ): bool {
		return DebiProSessionType::Payment === $reference
			&& DebiProSessionType::Payment === $incoming;
	}

	/**
	 * @param DebiProSessionType $reference
	 * @param DebiProSessionType $incoming
	 */
	private static function get_incompatibility_message( DebiProSessionType $reference, DebiProSessionType $incoming ): string {
		if ( DebiProSessionType::Installment === $reference ) {
			return __( 'The cart already contains an installment product. You cannot add more products.', 'debi-payment-for-woocommerce' );
		}

		if ( DebiProSessionType::Installment === $incoming ) {
			return __( 'You cannot add an installment product together with other products. Please empty the cart first.', 'debi-payment-for-woocommerce' );
		}

		return __( 'These products cannot be purchased together. Please empty the cart first.', 'debi-payment-for-woocommerce' );
	}

	/**
	 * @param array<string, array<string, mixed>> $cart_items
	 */
	private static function get_last_cart_item_type( array $cart_items ): ?DebiProSessionType {
		$last_item = end( $cart_items );
		if ( empty( $last_item['product_id'] ) ) {
			return null;
		}

		return self::get_product_type( (int) $last_item['product_id'] );
	}

	/**
	 * Get the Debi session type associated with a product.
	 *
	 * @param int $product_id
	 * @return DebiProSessionType|null
	 */
	private static function get_product_type( $product_id ): ?DebiProSessionType {
		$pf = DEBIPRO_Product_Meta::get_product_financing( (int) $product_id );

		if ( empty( $pf ) || ! is_array( $pf ) || empty( $pf['type'] ) ) {
			return null;
		}

		return $pf['type'];
	}
}
