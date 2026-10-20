<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Cli;

use DebiPro\Infrastructure\DebiClientFactory;
use DebiPro\Subscription\SubscriptionsTable;

/**
 * WP-CLI: wp debipro reconcile-subscriptions
 *
 * Refreshes the local subscriptions mirror ({base_prefix}debipro_subscriptions)
 * from live Debi data. Safety net when subscription webhooks were missed, so a
 * cancelled/paused subscription does not linger as "active" in membership gates.
 *
 * Each row is retrieved with its own site's Debi key (the key is a per-site
 * gateway setting), then upserted — status and amount always refresh; identity
 * columns (product/user) are only overwritten when Debi returns a value, so a
 * sparse resource never clobbers what checkout recorded.
 */
final class ReconcileSubscriptionsCommand {

	/**
	 * Register the command when WP-CLI is present.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'debipro reconcile-subscriptions',
			array( __CLASS__, 'run' ),
			array(
				'shortdesc' => 'Refresh the local Debi subscriptions mirror from live Debi data.',
			)
		);
	}

	/**
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max subscriptions to scan (stalest first). Default 500.
	 *
	 * [--dry-run]
	 * : List matching subscriptions without calling Debi.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		$limit   = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 500;
		$dry_run = isset( $assoc['dry-run'] );

		$rows = SubscriptionsTable::reconcile_rows( $limit );
		\WP_CLI::log( sprintf( 'Found %d mirrored subscription(s).', count( $rows ) ) );

		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		$updated      = 0;
		$failed       = 0;

		foreach ( $rows as $row ) {
			$id      = $row['subscription_id'];
			$blog_id = (int) $row['blog_id'];

			if ( $dry_run ) {
				\WP_CLI::log( sprintf( 'Would reconcile %s (blog %d)', $id, $blog_id ) );
				continue;
			}

			$switched = false;
			if ( $is_multisite && $blog_id > 0 && function_exists( 'switch_to_blog' ) ) {
				switch_to_blog( $blog_id );
				$switched = true;
			}

			try {
				$client = DebiClientFactory::create();
				$sub    = $client->subscriptions->retrieve( $id );
				SubscriptionsTable::upsert( SubscriptionsTable::row_from_resource( $sub ) );
				++$updated;
				\WP_CLI::log( sprintf( 'Reconciled %s → %s', $id, isset( $sub->status ) ? (string) $sub->status : '(no status)' ) );
			} catch ( \Throwable $e ) {
				++$failed;
				\WP_CLI::warning( sprintf( 'Failed %s (blog %d): %s', $id, $blog_id, $e->getMessage() ) );
			} finally {
				if ( $switched ) {
					restore_current_blog();
				}
			}
		}

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry run complete.' );
			return;
		}

		\WP_CLI::success( sprintf( 'Reconcile complete. %d updated, %d failed.', $updated, $failed ) );
	}
}
