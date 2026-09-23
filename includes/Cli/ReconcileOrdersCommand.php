<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Cli;

use DebiPro\Webhook\OrderLocator;
use DebiPro\Webhook\OrderSync;

/**
 * WP-CLI: wp debipro reconcile-orders
 *
 * Rebuilds payment projection meta (and WC status rules) from live Debi data
 * for open Debi-linked orders. Safety net when payment webhooks were missed.
 */
final class ReconcileOrdersCommand {

	/**
	 * Register the command when WP-CLI is present.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command(
			'debipro reconcile-orders',
			array( __CLASS__, 'run' ),
			array(
				'shortdesc' => 'Recompute Debi payment projections on open WooCommerce orders.',
			)
		);
	}

	/**
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Max orders to scan. Default 100.
	 *
	 * [--dry-run]
	 * : List matching orders without calling Debi.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public static function run( array $args, array $assoc ): void {
		$limit   = isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 100;
		$dry_run = isset( $assoc['dry-run'] );

		$orders = OrderLocator::find_open_debipro_orders( $limit );
		\WP_CLI::log( sprintf( 'Found %d Debi-linked order(s).', count( $orders ) ) );

		foreach ( $orders as $order ) {
			$id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
			if ( $dry_run ) {
				\WP_CLI::log( sprintf( 'Would reproject order #%d', $id ) );
				continue;
			}
			$result = OrderSync::reproject_order( $order );
			\WP_CLI::log( sprintf( 'Order #%d → %s', $id, $result ) );
		}

		\WP_CLI::success( $dry_run ? 'Dry run complete.' : 'Reconcile complete.' );
	}
}
