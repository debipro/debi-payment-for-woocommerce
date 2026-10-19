<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Subscription;

use Debi\Resource\Subscription;

/**
 * Local mirror of Debi subscriptions, so "is this user subscribed?" can be
 * answered without a Debi API round-trip.
 *
 * One flat table across the whole multisite network (keyed on the Debi
 * subscription id, scoped by a `blog_id` column), so it lives on the network
 * base prefix rather than a per-site prefix. Populated at checkout and kept
 * current by subscription / payment webhooks. It intentionally stores no
 * `next_charge_at` and no `kind` — only what a membership gate needs.
 */
final class SubscriptionsTable {

	/** Bump when the schema changes so {@see maybe_install()} re-runs dbDelta. */
	public const DB_VERSION = '1';

	private const VERSION_OPTION = 'debipro_subscriptions_db_version';
	private const TABLE          = 'debipro_subscriptions';

	/**
	 * Fully-qualified table name on the network base prefix (flat, cross-blog).
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::TABLE;
	}

	/**
	 * Create/upgrade the table when the stored schema version is behind. Cheap to
	 * call on every load (one option read); only runs dbDelta on a version bump.
	 * Deploys that `git pull` (no re-activation) still pick the schema up here.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		self::install();
		update_option( self::VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, KEY defs
		// on their own lines. $table / $charset are server-controlled, not input.
		$sql = "CREATE TABLE {$table} (
  subscription_id varchar(64) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id varchar(64) NOT NULL DEFAULT '',
  amount decimal(12,2) NOT NULL DEFAULT 0,
  status varchar(32) NOT NULL DEFAULT '',
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (subscription_id),
  KEY blog_user (blog_id, wp_user_id),
  KEY blog_product (blog_id, product_id)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Insert or update a subscription row. On update, identity columns are only
	 * overwritten when a non-empty value is supplied, so a status-only webhook
	 * never clobbers the product / user learned at checkout.
	 *
	 * @param array{
	 *     subscription_id: string,
	 *     blog_id?: int,
	 *     product_id?: int,
	 *     wp_user_id?: int,
	 *     customer_id?: string,
	 *     amount?: float,
	 *     status?: string
	 * } $row
	 */
	public static function upsert( array $row ): void {
		global $wpdb;

		$id = (string) ( $row['subscription_id'] ?? '' );
		if ( '' === $id ) {
			return;
		}

		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$exists = (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE subscription_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( $exists ) {
			$data = array( 'updated_at' => $now );
			if ( array_key_exists( 'status', $row ) ) {
				$data['status'] = (string) $row['status'];
			}
			if ( array_key_exists( 'amount', $row ) ) {
				$data['amount'] = (float) $row['amount'];
			}
			if ( ! empty( $row['customer_id'] ) ) {
				$data['customer_id'] = (string) $row['customer_id'];
			}
			if ( ! empty( $row['product_id'] ) ) {
				$data['product_id'] = (int) $row['product_id'];
			}
			if ( ! empty( $row['wp_user_id'] ) ) {
				$data['wp_user_id'] = (int) $row['wp_user_id'];
			}
			if ( ! empty( $row['blog_id'] ) ) {
				$data['blog_id'] = (int) $row['blog_id'];
			}
			$wpdb->update( $table, $data, array( 'subscription_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array(
				'subscription_id' => $id,
				'blog_id'         => (int) ( $row['blog_id'] ?? 0 ),
				'product_id'      => (int) ( $row['product_id'] ?? 0 ),
				'wp_user_id'      => (int) ( $row['wp_user_id'] ?? 0 ),
				'customer_id'     => (string) ( $row['customer_id'] ?? '' ),
				'amount'          => (float) ( $row['amount'] ?? 0 ),
				'status'          => (string) ( $row['status'] ?? '' ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
	}

	/**
	 * Build an upsert row from a Debi subscription resource, reading the ids we
	 * stored in its metadata at checkout.
	 *
	 * @return array<string, mixed>
	 */
	public static function row_from_resource( Subscription $sub ): array {
		$md       = $sub->metadata ?? null;
		$customer = $sub->customer ?? null;

		$customer_id = '';
		if ( is_object( $customer ) && isset( $customer->id ) ) {
			$customer_id = (string) $customer->id;
		} elseif ( is_string( $customer ) ) {
			$customer_id = $customer;
		}

		return array(
			'subscription_id' => isset( $sub->id ) ? (string) $sub->id : '',
			'blog_id'         => self::md_int( $md, 'blog_id' ),
			'product_id'      => self::md_int( $md, 'product_id' ),
			'wp_user_id'      => self::md_int( $md, 'wp_user_id' ),
			'customer_id'     => $customer_id,
			'amount'          => isset( $sub->amount ) ? (float) $sub->amount : 0.0,
			'status'          => isset( $sub->status ) ? (string) $sub->status : '',
		);
	}

	/**
	 * @param mixed $metadata DebiObject|array|null
	 */
	private static function md_int( $metadata, string $key ): int {
		if ( is_object( $metadata ) && isset( $metadata->$key ) ) {
			return (int) $metadata->$key;
		}
		if ( is_array( $metadata ) && isset( $metadata[ $key ] ) ) {
			return (int) $metadata[ $key ];
		}
		return 0;
	}
}
