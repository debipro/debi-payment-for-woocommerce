<?php
/**
 * Idempotent classic WooCommerce checkout setup for wp-env.
 *
 * Ensures cart/checkout pages use shortcodes (not blocks), activates the
 * Debi plugin, and runs seed data.
 *
 * @package Debi_Payment_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @param string $message Log line.
 */
function debi_classic_log( $message ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( '[debi-classic] ' . $message );
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	echo '[debi-classic] ' . $message . PHP_EOL;
}

/**
 * @param string $needle Folder slug fragment.
 * @return string|null Plugin basename relative to plugins dir.
 */
function debi_classic_resolve_plugin( $needle ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $plugin_file => $plugin_data ) {
		$folder = strtok( $plugin_file, '/' );
		if ( false !== strpos( $folder, $needle ) ) {
			return $plugin_file;
		}
		if ( isset( $plugin_data['TextDomain'] ) && $plugin_data['TextDomain'] === $needle ) {
			return $plugin_file;
		}
	}
	return null;
}

/**
 * @param string $option_name WooCommerce page option key.
 * @param string $title       Page title when creating a new page.
 * @param string $shortcode   Page content shortcode.
 */
function debi_classic_ensure_wc_page( $option_name, $title, $shortcode ) {
	$page_id = (int) get_option( $option_name, 0 );
	if ( $page_id > 0 && get_post( $page_id ) instanceof WP_Post ) {
		wp_update_post(
			array(
				'ID'           => $page_id,
				'post_content' => $shortcode,
			)
		);
		debi_classic_log( "updated {$title} page (ID {$page_id}) to classic shortcode" );
		return;
	}

	$new_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_content' => $shortcode,
			'post_status'  => 'publish',
		)
	);
	if ( is_wp_error( $new_id ) || ! $new_id ) {
		debi_classic_log( "failed to create {$title} page" );
		return;
	}
	update_option( $option_name, (int) $new_id );
	debi_classic_log( "created {$title} page (ID {$new_id})" );
}

/**
 * Run the classic checkout setup routine.
 */
function debi_classic_run_setup() {
	foreach ( array( 'debi-payment-for-woocommerce' ) as $slug ) {
		$plugin_file = debi_classic_resolve_plugin( $slug );
		if ( null === $plugin_file ) {
			debi_classic_log( "plugin '{$slug}' not found; skipping" );
			continue;
		}
		$result = activate_plugin( $plugin_file );
		if ( is_wp_error( $result ) ) {
			debi_classic_log( "failed to activate {$plugin_file}: " . $result->get_error_message() );
		} else {
			debi_classic_log( "activated {$plugin_file}" );
		}
	}

	debi_classic_ensure_wc_page( 'woocommerce_cart_page_id', 'Cart', '[woocommerce_cart]' );
	debi_classic_ensure_wc_page( 'woocommerce_checkout_page_id', 'Checkout', '[woocommerce_checkout]' );

	require __DIR__ . '/seed.php';
}

debi_classic_run_setup();
