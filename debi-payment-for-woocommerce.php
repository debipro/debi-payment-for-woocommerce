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
 * @package           Debi_Payment_For_WooCommerce
 *
 * @wordpress-plugin
 * Plugin Name:       Debi Payment for WooCommerce
 * Plugin URI:        https://github.com/debipro/debi-payment-for-woocommerce
 * Description:       Official Debi payment gateway integration for WooCommerce. Accept credit cards with installments and automatic debit payments.
 * Version:           1.4.0
 * Author:            DEBI
 * Author URI:        https://github.com/debipro
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       debi-payment-for-woocommerce
 * Domain Path:       /languages/
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 3.0
 * Tested up to:      7.1
 * WC tested up to:   9.8
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

if (!defined('DEBIPRO_PLUGIN_FILE')) {
	define('DEBIPRO_PLUGIN_FILE', __FILE__);
}
if (!defined('DEBIPRO_PLUGIN_URL')) {
	define('DEBIPRO_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('DEBIPRO_PLUGIN_VERSION')) {
	define('DEBIPRO_PLUGIN_VERSION', '1.4.0');
}
if (!defined('DEBIPRO_PLUGIN_DIR')) {
	define('DEBIPRO_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

add_action('before_woocommerce_init', function () {
	if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
	}
});

add_action('woocommerce_blocks_payment_method_type_registration', function ($registry) {
	require_once plugin_dir_path(__FILE__) . 'class-debipro-blocks.php';
	$registry->register(new DEBIPRO_Blocks_Integration());
});

/**
 * Dependency-free PSR-4 autoloader for the vendored Debi PHP SDK (`Debi\*` =>
 * lib/debi-php/src/*) and this plugin's own domain layer (`DebiPro\*` => includes/*).
 *
 * We vendor only the SDK source and inject our own `Debi\HttpClient\ClientInterface`
 * (DebiPro\Infrastructure\Http\WpDebiHttpClient) over wp_remote_request, so the SDK
 * never reaches for its PSR-17/18 + php-http/discovery `DefaultClient`. Registering a
 * tiny autoloader keeps the deploy a plain file copy (no `composer install` at runtime).
 * The SDK requires PHP 8.1+; classes load lazily, only on the payment/webhook paths.
 */
spl_autoload_register(
	static function ($class) {
		static $prefixes = array(
			'Debi\\'    => 'lib/debi-php/src/',
			'DebiPro\\' => 'includes/',
		);
		foreach ($prefixes as $prefix => $base_dir) {
			$len = strlen($prefix);
			if (0 !== strncmp($class, $prefix, $len)) {
				continue;
			}
			$relative = str_replace('\\', '/', substr($class, $len));
			$file     = DEBIPRO_PLUGIN_DIR . $base_dir . $relative . '.php';
			if (is_file($file)) {
				require_once $file;
			}
			return;
		}
	}
);

// Load the dependency-free key helpers (used by the gateway + admin validation).
if (!class_exists('DEBIPRO_Keys')) {
	require_once plugin_dir_path(__FILE__) . 'includes/class-debipro-keys.php';
}

/**
 * Load the plugin's bundled translations.
 *
 * Two WordPress facts make this non-trivial:
 *   1. Just-in-time loading only auto-covers wp-content/languages/plugins/, never a
 *      plugin's own bundled catalog — so the /languages folder needs an explicit load.
 *   2. Since WP 6.5 translations are stored keyed by the *active* locale. We ship a
 *      single Spanish catalog (es_ES), so for any Spanish variant with no bundle of
 *      its own (es_AR, es_MX, …) we load es_ES *under the active locale* — otherwise
 *      load_plugin_textdomain would file it under the es_ES slot and an es_AR lookup
 *      would miss it (which is exactly what the old es_AR→es_ES plugin_locale filter
 *      did, silently falling back to English on modern WordPress).
 *
 * Hooked on init per WP 6.7+ guidance (loading a textdomain earlier triggers a
 * _doing_it_wrong notice).
 */
add_action('init', 'debipro_load_textdomain');
function debipro_load_textdomain() {
	$domain = 'debi-payment-for-woocommerce';
	$locale = determine_locale();
	$dir    = plugin_dir_path(__FILE__) . 'languages/';

	// An exact catalog for the active locale wins (incl. user drop-ins here).
	$exact = $dir . $domain . '-' . $locale . '.mo';
	if (is_readable($exact)) {
		load_textdomain($domain, $exact, $locale);
		return;
	}

	// Any other Spanish variant reuses the es_ES catalog under the active locale.
	if (0 === strpos($locale, 'es')) {
		load_textdomain($domain, $dir . $domain . '-es_ES.mo', $locale);
		return;
	}

	// Non-Spanish: defer to the standard loader (also picks up translate.wordpress.org
	// packs in wp-content/languages/plugins/).
	load_plugin_textdomain($domain, false, dirname(plugin_basename(__FILE__)) . '/languages');
}

function debipro_add_payment_gateway($gateways) {
	$gateways[] = 'DEBIPRO_Payment_Gateway';
	return $gateways;
}

add_action('plugins_loaded', 'debipro_init_payment_gateway', 11);
function debipro_init_payment_gateway() {
	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	require_once plugin_dir_path(__FILE__) . 'includes/class-debipro-product-meta.php';
	DEBIPRO_Product_Meta::init();

	require_once plugin_dir_path(__FILE__) . 'includes/class-debipro-cart.php';
	DEBIPRO_Cart::init();

	require_once plugin_dir_path(__FILE__) . 'class-debipro-payment-gateway.php';

	add_filter( 'woocommerce_payment_gateways', 'debipro_add_payment_gateway' );

	// Registered here (not in the gateway constructor) so the AJAX test and
	// the settings-screen asset are available even when WooCommerce hasn't
	// instantiated the gateway for the current request.
	add_action('wp_ajax_debipro_test_connection', array('DEBIPRO_Payment_Gateway', 'ajax_test_connection'));
	add_action('wp_ajax_debipro_setup_webhook', array('DEBIPRO_Payment_Gateway', 'ajax_setup_webhook'));
	add_action('admin_enqueue_scripts', array('DEBIPRO_Payment_Gateway', 'enqueue_admin_assets'));

	// Webhook endpoint: POST /wp-json/debipro/v1/webhook. Registered on
	// rest_api_init so Debi payment events can drive the order projection.
	add_action('rest_api_init', array('DebiPro\\Webhook\\WebhookController', 'register_routes'));

	\DebiPro\Admin\OrderActions::init();
	\DebiPro\Admin\SubscriptionsPage::init();
	\DebiPro\Cli\ReconcileOrdersCommand::register();
	\DebiPro\Cli\ReconcileSubscriptionsCommand::register();
	\DebiPro\Cli\MigrateSessionTypesCommand::register();
	\DebiPro\Cli\SetupWebhooksCommand::register();

	// Ensure the local subscriptions mirror table exists / is up to date. Cheap
	// (one option read) unless the schema version changed.
	\DebiPro\Subscription\SubscriptionsTable::maybe_install();
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