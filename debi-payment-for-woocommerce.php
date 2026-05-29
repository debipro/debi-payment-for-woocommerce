<?php
/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://debi.pro
 * @since             1.0.0
 * @package           WooCommerce_Debi
 *
 * @wordpress-plugin
 * Plugin Name:       Debi Payment for WooCommerce
 * Plugin URI:        https://github.com/debipro/debi-payment-for-woocommerce
 * Description:       Official Debi payment gateway integration for WooCommerce. Accept credit cards with installments and automatic debit payments.
 * Version:           1.1.0
 * Author:            Fernando del Peral
 * Author URI:        https://github.com/debipro
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       debi-payment-for-woocommerce
 * Domain Path:       /languages/
 * Requires at least: 5.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 3.0
 * WC tested up to:   9.1
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

// Load API client class
if (!class_exists('DEBIPRO_debi')) {
	require_once plugin_dir_path(__FILE__) . 'debi.php';
}

// Map es_AR to es_ES for translations
add_filter('plugin_locale', 'debipro_map_locale', 10, 2);
function debipro_map_locale($locale, $domain) {
	if ($domain === 'debi-payment-for-woocommerce' && $locale === 'es_AR') {
		return 'es_ES';
	}
	return $locale;
}

// Load translations early
add_action('plugins_loaded', 'debipro_load_textdomain', 5);
function debipro_load_textdomain() {
	$locale = apply_filters('plugin_locale', get_locale(), 'debi-payment-for-woocommerce');
	$mofile = plugin_dir_path(__FILE__) . 'languages/debi-payment-for-woocommerce-' . $locale . '.mo';
	load_textdomain('debi-payment-for-woocommerce', $mofile);
}

if (debipro_is_woocommerce_active()) {
	add_filter('woocommerce_payment_gateways', 'debipro_add_payment_gateway');
	function debipro_add_payment_gateway($gateways) {
		$gateways[] = 'DEBIPRO_Payment_Gateway';
		return $gateways;
	}

	add_action('plugins_loaded', 'debipro_init_payment_gateway', 10);
	function debipro_init_payment_gateway() {
		require_once plugin_dir_path(__FILE__) . 'class-wc-debi.php';
	}

}

/**
 * @return bool
 */
function debipro_is_woocommerce_active() {
	$active_plugins = (array) get_option('active_plugins', array());

	if (is_multisite()) {
		$active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
	}

	return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}