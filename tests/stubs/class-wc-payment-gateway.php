<?php
/**
 * Minimal WooCommerce payment gateway stub for unit tests.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
	/**
	 * Bare minimum of WC_Settings_API used by DEBIPRO_Payment_Gateway tests.
	 */
	class WC_Payment_Gateway {

		/** @var string */
		public $id = '';

		/** @var string */
		public $plugin_id = 'woocommerce_';

		/**
		 * @param string $key Field key.
		 */
		public function get_field_key( $key ): string {
			return $this->plugin_id . $this->id . '_' . $key;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_post_data(): array {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test stub; mirrors WC_Settings_API.
			return $_POST;
		}

		/**
		 * @param string $key            Option key.
		 * @param mixed  $default_value Default value.
		 * @return mixed
		 */
		public function get_option( $key, $default_value = '' ) {
			return $default_value;
		}

		/**
		 * @return bool
		 */
		public function process_admin_options() {
			return true;
		}
	}
}
