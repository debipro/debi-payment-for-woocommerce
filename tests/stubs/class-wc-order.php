<?php
/**
 * Minimal global WC_Order test double for host-based unit tests.
 *
 * Tracks only what the webhook OrderSync touches (status, meta, notes). Defined
 * in the global namespace so production `instanceof \WC_Order` checks pass.
 *
 * @package Debi_Payment_For_WooCommerce
 */

declare( strict_types=1 );

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
