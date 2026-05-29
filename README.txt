=== Debi Payment for WooCommerce ===
Contributors: fernandodelperal
Tags: payment, gateway, debit, installments, subscriptions
Requires at least: 5.6
Tested up to: 6.8
Stable tag: 1.1.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Official Debi payment gateway integration for WooCommerce. Accept payments with flexible installments and automatic debit.

== Description ==

Debi Payment for WooCommerce is the official payment gateway integration for Debi.pro, allowing your WooCommerce store to accept credit and debit card payments with flexible installment plans.

= Key Features =

* Simple configuration - Set up in minutes
* Flexible installment plans - Up to 12 installments
* Customizable interest rates - Configure interest for each installment plan
* Automatic debit - Schedule recurring payments
* Sandbox mode - Test before going live
* Secure payments - PCI-compliant payment processing
* Full WooCommerce integration - Works seamlessly with your existing WooCommerce store
* Translation ready - Full internationalization support

= How It Works =

1. Install and activate the plugin
2. Configure your Debi API credentials (live and sandbox)
3. Set up your desired installment plans with interest rates
4. Customers can now select from your configured payment plans during checkout

= Requirements =

* WordPress 5.6 or higher
* PHP 8.1 or higher
* WooCommerce 3.0 or higher

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Navigate to Plugins → Add New
3. Search for "Debi Payment for WooCommerce"
4. Click "Install Now"
5. Activate the plugin
6. Configure the plugin at WooCommerce → Settings → Payments → Debi

= Manual Installation =

1. Download the plugin zip file
2. Extract it to your WordPress plugins directory (wp-content/plugins/)
3. Log in to your WordPress admin panel
4. Navigate to Plugins
5. Find "Debi Payment for WooCommerce" and click "Activate"
6. Configure the plugin at WooCommerce → Settings → Payments → Debi

= Configuration =

1. Go to WooCommerce → Settings → Payments
2. Find "Debi" and click "Manage"
3. Enable the payment method
4. Enter your Debi API credentials:
   * Live Token
   * Sandbox Token
5. Configure installment plans and interest rates (0-12 installments)
6. Choose to enable/disable sandbox mode
7. Save changes

= Getting Your API Credentials =

Production:
1. Sign up at https://debi.pro/register for production
2. Navigate to the Developer section https://debi.pro/dashboard/developers
3. Copy your API token (secret key) and paste it in the plugin settings
4. Save changes

Sandbox:
1. Sign up at https://debi-test.pro/register for sandbox
2. Navigate to the Developer section https://debi-test.pro/dashboard/developers
3. Copy your API token (secret key) and paste it in the plugin settings
4. Save changes

== Frequently Asked Questions ==

= Do I need a Debi account to use this plugin? =

Yes, you need to create an account at https://debi.pro and obtain your API credentials.

= What payment methods are supported? =

The plugin supports credit and debit cards. Payment processing is handled securely by Debi's infrastructure.

= Can I customize the installment plans? =

Yes, you can configure up to 12 different installment plans with custom interest rates for each.

= Is this plugin PCI-compliant? =

Yes, the plugin integrates with Debi's PCI-compliant payment infrastructure, so you don't need to handle card data directly.

= Does this plugin support refunds? =

Refunds should be handled through your Debi dashboard or by contacting Debi support directly.

= Can I test before going live? =

Yes, the plugin includes a sandbox mode for testing with test credentials.

= Can I translate the plugin to my language? =

Yes! The plugin is fully translatable. Translation files can be created using the .pot template in the languages folder.

== Screenshots ==

1. Payment method settings page
2. Checkout with installment selection
3. Order with payment details

== Changelog ==

= 1.1.0 =
* All user-facing text converted to translatable strings
* Updated all code comments to English
* Full internationalization support added
* Security improvements with proper sanitization
* Improved error handling
* Added support for WooCommerce 8.0
* Code quality improvements

= 1.0.0 =
* Initial release
* Basic payment gateway functionality
* Installment plan configuration
* Sandbox mode support

== Upgrade Notice ==

= 1.1.0 =
Internationalization and security improvements. Recommended for all users.

= 1.0.0 =
Initial release.

== Support ==

For support, please visit:
https://debi.pro/docs

== Credits ==

Developed by Fernando del Peral for Debi.pro

== Privacy Policy ==

This plugin does not collect any personal data. All payment processing is handled by Debi's secure infrastructure.

Card details are never stored by this plugin and are sent directly to Debi's secure payment API.

== Development ==

= Contributing =

We welcome contributions! Please ensure all code follows WordPress coding standards.

= Testing =

Before submitting changes, please ensure:
* All code follows WordPress coding standards
* PHP compatibility is maintained
* WooCommerce compatibility is maintained
* Security best practices are followed
* All text is translatable

