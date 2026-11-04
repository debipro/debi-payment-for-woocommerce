<?php
/**
 * Minimal global WC_Cart test double for host-based unit tests.
 *
 * Defined in the global namespace so production `instanceof \WC_Cart` checks
 * pass. Tracks only the cart lines the needs-payment filters inspect.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Cart' ) ) {
	class WC_Cart {
		/** @param array<string,array{product_id:int}> $items */
		public function __construct( private array $items = array() ) {}

		/** @return array<string,array{product_id:int}> */
		public function get_cart(): array {
			return $this->items;
		}
	}
}
