<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer autoload and the Yoast PHPUnit polyfills. Real WordPress
 * integration tests will be wired up alongside the plugin rewrite; this
 * bootstrap intentionally stays minimal during the scaffolding phase.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

// Yoast PHPUnit Polyfills v2 registers itself via Composer autoload — no explicit load() needed.

define( 'DEBI_PLUGIN_DIR', dirname( __DIR__ ) );
define( 'DEBI_PLUGIN_FILE', DEBI_PLUGIN_DIR . '/debi-payment-for-woocommerce.php' );

/**
 * Minimal global WC_Order test double for host-based unit tests.
 *
 * Tracks only what the webhook OrderSync touches (status, meta, notes). Defined
 * in the global namespace so production `instanceof \WC_Order` checks pass.
 */
if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		/** @var string */
		public $status = 'processing';
		/** @var array<string,mixed> */
		public $meta = array();
		/** @var array<int,string> */
		public $notes = array();
		/** @var bool */
		public $saved = false;

		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		public function has_status( $status ) {
			return $this->status === $status;
		}

		public function update_status( $status, $note = '' ) {
			$this->status  = $status;
			$this->notes[] = $note;
		}

		public function save() {
			$this->saved = true;
		}
	}
}
