<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Cli;

/**
 * WP-CLI: wp debipro migrate-session-types
 *
 * Renames the legacy per-product `_debipro_type` meta to
 * `_debipro_default_session_type` and the gateway setting `default_type` to
 * `default_session_type`, mapping the old `one_time` value to `payment`. The
 * `installment` value is unchanged.
 *
 * Previews by default; pass --apply to write. Idempotent: products already on
 * the new key are skipped, so it is safe to re-run. Scoped to the current site
 * (run with --url=<site> for a specific multisite blog).
 */
final class MigrateSessionTypesCommand {

	private const OLD_META_KEY = '_debipro_type';
	private const NEW_META_KEY = \DEBIPRO_Product_Meta::SESSION_TYPE_KEY;
	private const OLD_OPTION   = 'default_type';
	private const NEW_OPTION   = 'default_session_type';

	/**
	 * Register the command when WP-CLI is present.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'debipro migrate-session-types',
			array( __CLASS__, 'run' ),
			array(
				'shortdesc' => 'Rename legacy _debipro_type product meta and default_type setting to the session-type names.',
			)
		);
	}

	/**
	 * Map a legacy type value to the new session-type value.
	 */
	private static function map_value( string $value ): string {
		return 'one_time' === $value ? 'payment' : $value;
	}

	/**
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Write the changes. Without it the command only reports what it would do.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc_args
	 */
	public static function run( $args, $assoc_args ): void {
		unset( $args );
		require_once plugin_dir_path( __DIR__ ) . 'debipro-session-type.php';

		$apply = isset( $assoc_args['apply'] );

		global $wpdb;

		// Products still on the legacy meta key.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::OLD_META_KEY
			)
		);

		$migrated = 0;
		$skipped  = 0;

		foreach ( $rows as $row ) {
			$post_id = (int) $row->post_id;
			$mapped  = self::map_value( (string) $row->meta_value );

			if ( null === \DebiProSessionType::tryFrom( $mapped ) ) {
				\WP_CLI::warning( sprintf( 'Product %d has an unrecognised type "%s"; skipping.', $post_id, (string) $row->meta_value ) );
				++$skipped;
				continue;
			}

			\WP_CLI::log( sprintf( '%s product %d: %s -> %s', $apply ? 'Migrating' : 'Would migrate', $post_id, (string) $row->meta_value, $mapped ) );

			if ( $apply ) {
				update_post_meta( $post_id, self::NEW_META_KEY, $mapped );
				delete_post_meta( $post_id, self::OLD_META_KEY );
			}
			++$migrated;
		}

		// Gateway settings option.
		$settings = get_option( 'woocommerce_debipro_settings', array() );
		if ( is_array( $settings ) && array_key_exists( self::OLD_OPTION, $settings ) ) {
			$mapped = self::map_value( (string) $settings[ self::OLD_OPTION ] );
			\WP_CLI::log( sprintf( '%s setting default_type -> default_session_type (%s)', $apply ? 'Migrating' : 'Would migrate', $mapped ) );

			if ( $apply ) {
				if ( ! array_key_exists( self::NEW_OPTION, $settings ) ) {
					$settings[ self::NEW_OPTION ] = $mapped;
				}
				unset( $settings[ self::OLD_OPTION ] );
				update_option( 'woocommerce_debipro_settings', $settings );
			}
		}

		$summary = sprintf( '%d product(s) %s, %d skipped.', $migrated, $apply ? 'migrated' : 'to migrate', $skipped );
		if ( $apply ) {
			\WP_CLI::success( $summary );
		} else {
			\WP_CLI::success( $summary . ' Re-run with --apply to write.' );
		}
	}
}
