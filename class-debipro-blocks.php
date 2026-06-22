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
	}

	public function is_active() {
		return $this->gateway && $this->gateway->is_available();
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'debi-sdk',
			'https://js.debi.pro/v1/',
			[],
			DEBIPRO_PLUGIN_VERSION,
			true
		);
		wp_register_script(
			'debipro-checkout-block',
			plugin_dir_url(__FILE__) . 'assets/js/checkout-block.js',
			['wc-blocks-registry', 'wc-settings', 'wp-element', 'debi-sdk'],
			'1.2.2',
			true
		);
		return ['debipro-checkout-block'];
	}

	public function get_payment_method_data() {
		if (!$this->gateway) {
			return [];
		}

		$installment_options = $this->gateway->get_installment_options_for_cart();
		$public_key          = trim((string) $this->gateway->get_option('publishable_key'));

		return [
			'title'               => $this->gateway->get_option('title') ?: 'Debi Payment',
			'description'         => $this->gateway->get_option('description') ?: '',
			'supports'            => $this->get_supported_features(),
			'installment_options' => $installment_options,
			'publishable_key'     => $public_key,
			'payment_flow'        => $this->gateway->get_option('payment_flow', 'onsite'),
		];
	}
}
