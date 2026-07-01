<?php
/**
 * WooCommerce Blocks payment method integration for Debi.
 *
 * @package WooCommerce_Debi
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class DEBIPRO_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'debipro';

	/** @var DEBIPRO_Payment_Gateway | null*/
	private $gateway;

	public function initialize() {
		if (!function_exists('WC')) {
			return;
		}

		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->gateway = $gateways['debipro'] ?? null;

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_checkout_block_assets' ), 20 );
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		$this->enqueue_checkout_block_scripts();
		return array( 'debipro-checkout-block' );
	}

	public function maybe_enqueue_checkout_block_assets() {
		if ( ! $this->is_active() ) {
			return;
		}

		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		if (
			! class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' )
			|| ! \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_checkout_block_default()
		) {
			return;
		}

		wp_enqueue_style(
			'debipro-checkout-block',
			plugin_dir_url( __FILE__ ) . 'assets/css/checkout-block.css',
			array(),
			DEBIPRO_PLUGIN_VERSION
		);

		$this->enqueue_checkout_block_scripts();
	}

	private function enqueue_checkout_block_scripts() {
		if ( wp_script_is( 'debipro-checkout-block', 'registered' ) ) {
			return;
		}

		wp_enqueue_script(
			'debi-sdk',
			'https://js.debi.pro/v1/',
			array(),
			DEBIPRO_PLUGIN_VERSION,
			true
		);
		wp_enqueue_script(
			'debipro-checkout-block',
			plugin_dir_url( __FILE__ ) . 'assets/js/checkout-block.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'debi-sdk' ),
			DEBIPRO_PLUGIN_VERSION,
			true
		);
	}

	public function get_payment_method_data() {
		if (!$this->gateway) {
			return [];
		}

		$installment_options = $this->gateway->get_installment_options_for_cart();
		$public_key          = trim((string) $this->gateway->get_option('publishable_key'));

		return [
			'title'               => $this->gateway->get_option('title') ?: 'Debi Payment',
			'description'         => $this->gateway->get_description(),
			'icon'                => DEBIPRO_Payment_Gateway::get_icon_url(),
			'supports'            => $this->get_supported_features(),
			'installment_options' => $installment_options,
			'publishable_key'     => $public_key,
			'i18n'                => $this->gateway->get_checkout_i18n(),
		];
	}
}
