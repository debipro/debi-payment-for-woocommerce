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

if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', true );
}

require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-product-meta.php';
require_once DEBI_PLUGIN_DIR . '/includes/class-debipro-cart.php';
require_once __DIR__ . '/stubs/wp-meta-functions.php';
require_once __DIR__ . '/stubs/wc-functions.php';
require_once __DIR__ . '/stubs/class-wc-order.php';
