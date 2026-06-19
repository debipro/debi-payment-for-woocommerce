<?php
/**
 * WordPress meta function stubs for unit tests.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param mixed $str String to sanitize.
	 */
	function sanitize_text_field( $str ): string {
		if ( ! is_scalar( $str ) ) {
			return '';
		}

		return trim( (string) $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}
