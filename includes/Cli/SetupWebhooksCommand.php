<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Cli;

use DEBIPRO_Keys;
use DEBIPRO_Payment_Gateway;
use DebiPro\Webhook\WebhookInstaller;

/**
 * WP-CLI: wp debipro setup-webhooks
 *
 * Creates or updates the Debi webhook endpoint for a site (or every site on a
 * multisite network) and stores its signing secret, keeping the endpoint
 * subscribed to exactly the events the gateway handles ({@see WebhookInstaller::EVENTS}).
 *
 * Scriptable equivalent of the "Set up webhook" button in the gateway settings —
 * useful after a deploy that changes the subscribed event set, so you do not have
 * to click through each store on a multisite network. Each site has its own Debi
 * secret key (read from its gateway settings), so the command runs per blog; sites
 * without a valid secret key, and local sites, are skipped.
 */
final class SetupWebhooksCommand {

	private const GATEWAY_OPTION = 'woocommerce_debipro_settings';

	/**
	 * Register the command when WP-CLI is present.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'debipro setup-webhooks',
			array( __CLASS__, 'run' ),
			array(
				'shortdesc' => 'Create/update the Debi webhook endpoint(s) and sync their subscribed events.',
			)
		);
	}

	/**
	 * ## OPTIONS
	 *
	 * [--all-sites]
	 * : Run for every site on the network (multisite). Default: the current site only.
	 *
	 * [--dry-run]
	 * : List the sites that would be set up, without calling Debi.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		$all_sites = isset( $assoc['all-sites'] );
		$dry_run   = isset( $assoc['dry-run'] );

		if ( $all_sites && is_multisite() ) {
			foreach ( get_sites(
				array(
					'number' => 0,
					'fields' => 'ids',
				)
			) as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				self::setup_current_site( $dry_run );
				restore_current_blog();
			}
		} else {
			self::setup_current_site( $dry_run );
		}

		\WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Webhook setup complete.' );
	}

	/**
	 * Create/update the endpoint for the current blog, or report why it is skipped.
	 */
	private static function setup_current_site( bool $dry_run ): void {
		$label  = home_url();
		$url    = DEBIPRO_Payment_Gateway::webhook_url();
		$secret = self::secret_key();

		if ( DEBIPRO_Payment_Gateway::is_local_site() ) {
			\WP_CLI::log( sprintf( 'Skip %s: local site.', $label ) );
			return;
		}
		if ( 'secret' !== DEBIPRO_Keys::kind( $secret ) ) {
			\WP_CLI::log( sprintf( 'Skip %s: no valid Debi secret key configured.', $label ) );
			return;
		}

		if ( $dry_run ) {
			\WP_CLI::log( sprintf( 'Would set up webhook for %s -> %s', $label, $url ) );
			return;
		}

		try {
			$result = WebhookInstaller::ensure( $secret, $url );
		} catch ( \Throwable $e ) {
			\WP_CLI::warning( sprintf( 'Failed %s: %s', $label, $e->getMessage() ) );
			return;
		}

		if ( '' !== (string) $result['secret'] ) {
			self::save_webhook_secret( (string) $result['secret'] );
		}

		if ( ! empty( $result['created'] ) ) {
			$state = 'created';
		} elseif ( ! empty( $result['updated'] ) ) {
			$state = 'updated (events synced)';
		} else {
			$state = 'unchanged';
		}
		\WP_CLI::log( sprintf( '%s: %s (endpoint %s)', $label, $state, '' !== (string) $result['id'] ? (string) $result['id'] : '(no id)' ) );
	}

	private static function secret_key(): string {
		$settings = get_option( self::GATEWAY_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return trim( (string) ( $settings['secret_key'] ?? '' ) );
	}

	private static function save_webhook_secret( string $secret ): void {
		$settings = get_option( self::GATEWAY_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings['webhook_secret'] = $secret;
		update_option( self::GATEWAY_OPTION, $settings );
	}
}
