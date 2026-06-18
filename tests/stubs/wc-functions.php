<?php
/**
 * WooCommerce function stubs for unit tests.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Test harness for WooCommerce. Tests set $GLOBALS['debipro_wc_stub'].
	 */
	// phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- mirrors WooCommerce API.
	function WC() {
		return $GLOBALS['debipro_wc_stub'] ?? null;
	}
}
