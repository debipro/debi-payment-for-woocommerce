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

// Hook no-ops so code under test (e.g. OrderProjector's `do_action`) runs without
// a full WordPress load, and independently of whether a Brain Monkey test ran
// first. Brain Monkey can still redefine these for tests that assert on them.
if ( ! function_exists( 'do_action' ) ) {
	/**
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Hook args.
	 */
	function do_action( $hook_name, ...$args ): void {}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * @param string $hook_name Hook name.
	 * @param mixed  $value     Value to filter (returned unchanged).
	 * @param mixed  ...$args   Extra args.
	 * @return mixed
	 */
	function apply_filters( $hook_name, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param mixed ...$args Ignored.
	 */
	function add_action( ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param mixed ...$args Ignored.
	 */
	function add_filter( ...$args ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data to encode.
	 * @param int   $options JSON options.
	 * @param int   $depth Max depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return wp_json_encode_native( $data, (int) $options, (int) $depth );
	}
}

/**
 * Thin wrapper so the stub above delegates to the real encoder.
 *
 * @param mixed $data Data to encode.
 * @param int   $options JSON options.
 * @param int   $depth Max depth.
 * @return string|false
 */
function wp_json_encode_native( $data, int $options, int $depth ) {
	return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}
