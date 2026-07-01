<?php
/**
 * Workbench seeder for debi-payment-for-woocommerce.
 *
 * Invoked via:
 *   wp eval-file wp-content/plugins/debi-payment-for-woocommerce/bin/seed.php
 *
 * Idempotent: safe to run multiple times. Reads fixtures from bin/fixtures.json.
 *
 * Behavior:
 *   - Single-site:   ensure 1 customer + 3 plain WC products + Debi gateway config.
 *   - Multisite:     network-activate WC + Debi, create 2 subsites, then run the
 *                    single-site seeding routine on the root site and each subsite.
 *
 * This file is NOT part of the distributed plugin (.distignore excludes bin/).
 *
 * @package Debi_Payment_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Log a one-line message to STDOUT (visible in `npm start` output).
 *
 * @param string $message Plain text message.
 */
function debi_seed_log( $message ) {
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		WP_CLI::log( '[debi-seed] ' . $message );
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI seeder; output is for the developer's terminal, not HTML.
	echo '[debi-seed] ' . $message . PHP_EOL;
}

/**
 * Load fixtures from bin/fixtures.json relative to this file.
 *
 * @return array<string,mixed>
 */
function debi_seed_load_fixtures() {
	$path = __DIR__ . '/fixtures.json';
	if ( ! file_exists( $path ) ) {
		debi_seed_log( 'fixtures.json not found at ' . $path );
		return array();
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file, not a remote URL.
	$decoded = json_decode( file_get_contents( $path ), true );
	if ( ! is_array( $decoded ) ) {
		debi_seed_log( 'fixtures.json is not valid JSON' );
		return array();
	}
	return $decoded;
}

/**
 * Ensure a customer user exists. Idempotent by user_login.
 *
 * @param array<string,mixed> $spec Fixture spec.
 * @return int|null User ID, or null on failure.
 */
function debi_seed_ensure_customer( array $spec ) {
	$login = isset( $spec['user_login'] ) ? $spec['user_login'] : '';
	if ( '' === $login ) {
		return null;
	}
	$existing = get_user_by( 'login', $login );
	if ( $existing instanceof WP_User ) {
		debi_seed_log( "customer '{$login}' already exists (ID {$existing->ID})" );
		return (int) $existing->ID;
	}
	$user_id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_email' => isset( $spec['user_email'] ) ? $spec['user_email'] : '',
			'user_pass'  => isset( $spec['user_pass'] ) ? $spec['user_pass'] : wp_generate_password(),
			'first_name' => isset( $spec['first_name'] ) ? $spec['first_name'] : '',
			'last_name'  => isset( $spec['last_name'] ) ? $spec['last_name'] : '',
			'role'       => isset( $spec['role'] ) ? $spec['role'] : 'customer',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		debi_seed_log( 'failed to create customer: ' . $user_id->get_error_message() );
		return null;
	}
	debi_seed_log( "created customer '{$login}' (ID {$user_id})" );
	return (int) $user_id;
}

/**
 * Ensure a simple WooCommerce product exists. Idempotent by SKU.
 *
 * @param array<string,mixed> $spec Fixture spec.
 * @return int|null Product ID, or null on failure.
 */
function debi_seed_ensure_product( array $spec ) {
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		debi_seed_log( 'WooCommerce not loaded; skipping product seeding' );
		return null;
	}
	$sku = isset( $spec['sku'] ) ? $spec['sku'] : '';
	if ( '' === $sku ) {
		return null;
	}
	$existing_id = wc_get_product_id_by_sku( $sku );
	if ( $existing_id ) {
		debi_seed_log( "product SKU {$sku} already exists (ID {$existing_id})" );
		return (int) $existing_id;
	}
	$product = new WC_Product_Simple();
	$product->set_name( isset( $spec['name'] ) ? $spec['name'] : 'Demo Product' );
	$product->set_sku( $sku );
	$product->set_regular_price( isset( $spec['price'] ) ? (string) $spec['price'] : '0' );
	$product->set_description( isset( $spec['description'] ) ? $spec['description'] : '' );
	$product->set_status( 'publish' );
	$product->set_manage_stock( true );
	$product->set_stock_quantity( isset( $spec['stock'] ) ? (int) $spec['stock'] : 100 );
	$product->set_stock_status( 'instock' );
	$id = $product->save();
	debi_seed_log( "created product '{$spec['name']}' (ID {$id}, SKU {$sku})" );
	return (int) $id;
}

/**
 * Enable and configure the Debi payment gateway. Idempotent.
 *
 * Writes the WooCommerce gateway settings option directly. Real API tokens are
 * left blank — each dev fills them via WC → Settings → Payments → Debi.
 *
 * @param array<string,mixed> $gateway Fixture spec.
 */
function debi_seed_configure_gateway( array $gateway ) {
	$gateway_id = isset( $gateway['gateway_id'] ) ? $gateway['gateway_id'] : 'debi';
	$option_key = 'woocommerce_' . $gateway_id . '_settings';
	$current    = get_option( $option_key, array() );
	if ( ! is_array( $current ) ) {
		$current = array();
	}
	$desired = array(
		'enabled' => isset( $gateway['enabled'] ) ? $gateway['enabled'] : 'yes',
		'title'   => isset( $gateway['title'] ) ? $gateway['title'] : 'Debi',
	);
	$merged  = array_merge( $current, $desired );
	if ( $merged === $current ) {
		debi_seed_log( "gateway '{$gateway_id}' already configured" );
		return;
	}
	update_option( $option_key, $merged );
	debi_seed_log( "configured gateway '{$gateway_id}'" );
}

/**
 * Run the single-site seeding routine in the currently active blog context.
 *
 * @param array<string,mixed> $fixtures Fixture data.
 */
function debi_seed_run_for_current_site( array $fixtures ) {
	if ( isset( $fixtures['customer'] ) && is_array( $fixtures['customer'] ) ) {
		debi_seed_ensure_customer( $fixtures['customer'] );
	}
	if ( isset( $fixtures['products'] ) && is_array( $fixtures['products'] ) ) {
		foreach ( $fixtures['products'] as $product_spec ) {
			if ( is_array( $product_spec ) ) {
				debi_seed_ensure_product( $product_spec );
			}
		}
	}
	if ( isset( $fixtures['gateway'] ) && is_array( $fixtures['gateway'] ) ) {
		debi_seed_configure_gateway( $fixtures['gateway'] );
	}
}

/**
 * Ensure a subsite exists. Idempotent by slug.
 *
 * @param string $slug   Path slug, e.g. 'shop-one'.
 * @param string $title  Site title.
 * @return int|null Blog ID, or null on failure.
 */
function debi_seed_ensure_subsite( $slug, $title ) {
	$network = get_network();
	if ( ! $network ) {
		return null;
	}
	$path     = '/' . trim( $slug, '/' ) . '/';
	$existing = get_blog_id_from_url( $network->domain, $path );
	if ( $existing ) {
		debi_seed_log( "subsite '{$slug}' already exists (blog ID {$existing})" );
		return (int) $existing;
	}
	$blog_id = wpmu_create_blog( $network->domain, $path, $title, get_current_user_id(), array( 'public' => 1 ), $network->id );
	if ( is_wp_error( $blog_id ) ) {
		debi_seed_log( "failed to create subsite '{$slug}': " . $blog_id->get_error_message() );
		return null;
	}
	debi_seed_log( "created subsite '{$slug}' (blog ID {$blog_id})" );
	return (int) $blog_id;
}

/**
 * Resolve an installed plugin's entry file by matching either its folder slug or
 * its TextDomain / Name header. Returns the path relative to wp-content/plugins
 * (e.g. 'woocommerce.latest-stable/woocommerce.php') or null if not found.
 *
 * Needed because wp-env installs ZIP-sourced plugins under the ZIP slug
 * (e.g. 'woocommerce.latest-stable'), not the canonical plugin folder.
 *
 * @param string $needle Folder slug or TextDomain to look up.
 * @return string|null Plugin file relative to wp-content/plugins, or null.
 */
function debi_seed_resolve_plugin_file( $needle ) {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	foreach ( get_plugins() as $plugin_file => $plugin_data ) {
		$folder = strtok( $plugin_file, '/' );
		if ( $folder === $needle ) {
			return $plugin_file;
		}
		if ( isset( $plugin_data['TextDomain'] ) && $plugin_data['TextDomain'] === $needle ) {
			return $plugin_file;
		}
	}
	return null;
}

/**
 * Network-activate a plugin by folder slug or TextDomain. Idempotent.
 *
 * @param string $needle Plugin folder slug or TextDomain (e.g. 'woocommerce').
 */
function debi_seed_network_activate( $needle ) {
	$plugin_file = debi_seed_resolve_plugin_file( $needle );
	if ( null === $plugin_file ) {
		debi_seed_log( "plugin '{$needle}' not installed; skipping network activation" );
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( is_plugin_active_for_network( $plugin_file ) ) {
		debi_seed_log( "plugin '{$plugin_file}' already network-activated" );
		return;
	}
	$result = activate_plugin( $plugin_file, '', true, true );
	if ( is_wp_error( $result ) ) {
		debi_seed_log( "failed to network-activate '{$plugin_file}': " . $result->get_error_message() );
		return;
	}
	debi_seed_log( "network-activated '{$plugin_file}'" );
}

// -----------------------------------------------------------------------------
// Entry point
// -----------------------------------------------------------------------------

$fixtures = debi_seed_load_fixtures();
if ( empty( $fixtures ) ) {
	debi_seed_log( 'no fixtures loaded; aborting' );
	return;
}

if ( is_multisite() ) {
	debi_seed_log( 'multisite detected; network-activating plugins and seeding all sites' );

	debi_seed_network_activate( 'woocommerce' );
	debi_seed_network_activate( 'debi-payment-for-woocommerce' );

	if ( isset( $fixtures['subsites'] ) && is_array( $fixtures['subsites'] ) ) {
		foreach ( $fixtures['subsites'] as $subsite_spec ) {
			if ( isset( $subsite_spec['slug'], $subsite_spec['title'] ) ) {
				debi_seed_ensure_subsite( $subsite_spec['slug'], $subsite_spec['title'] );
			}
		}
	}

	$sites = get_sites( array( 'number' => 100 ) );
	foreach ( $sites as $site ) {
		$site_id = (int) $site->blog_id;
		switch_to_blog( $site_id );
		debi_seed_log( "--- seeding blog ID {$site_id} ({$site->domain}{$site->path}) ---" );
		debi_seed_run_for_current_site( $fixtures );
		restore_current_blog();
	}
} else {
	debi_seed_log( 'single-site mode; seeding the current site' );
	debi_seed_run_for_current_site( $fixtures );
}

debi_seed_log( 'done.' );
