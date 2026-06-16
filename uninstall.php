<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @link              https://debi.pro
 * @since             1.0.0
 * @package           Debi_Payment_For_WooCommerce
 * @license           GPL-2.0-or-later
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Clean up plugin settings and data
 *
 * @since 1.0.0
 */
function debipro_uninstall() {
	// Note: We're NOT deleting order meta data as per WordPress best practices
	// Order data should be preserved even after plugin uninstallation
	
	// Remove plugin-specific options if needed
	// delete_option('debipro_settings');
	
	// Clear any transients if they exist
	// global $wpdb;
	// $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_debipro_%'");
}

// Run uninstall function
debipro_uninstall();

