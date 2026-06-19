<?php
/**
 * WooCommerce admin settings stub for unit tests.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Admin_Settings', false ) ) {
	/**
	 * Captures admin settings errors for assertions.
	 */
	class WC_Admin_Settings {

		/** @var list<string> */
		public static array $errors = array();

		/**
		 * @param string $error Error message.
		 */
		public static function add_error( $error ): void {
			self::$errors[] = (string) $error;
		}

		public static function reset_errors(): void {
			self::$errors = array();
		}
	}
}
