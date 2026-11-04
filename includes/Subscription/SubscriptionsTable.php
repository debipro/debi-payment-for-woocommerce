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
 * A per-site table (on `$wpdb->prefix`): a subscription belongs to the store
 * (blog) it was bought on, which on multisite has its own Debi account, webhook
 * secret and orders. Populated at checkout and kept current by this site's
 * subscription / payment webhooks (the webhook endpoint is per-site, so it
 * already runs in the right blog context).
 *
 * The row mirrors the Debi subscription resource: besides the ids needed by a
 * membership gate, it stores the full schedule (amount, currency, interval,
 * day-of-month/week, start/first dates, upcoming charges), the expanded customer
 * and payment-method summaries, and Debi's own timestamps. {@see row_from_resource()}
 * maps a {@see Subscription} to a row; every write path (checkout, webhook,
 * reconcile, CLI) funnels through it, so the mirror tracks live Debi state.
 *
 * `created_at` / `updated_at` are this mirror's own row timestamps (last local
 * sync); Debi's are kept separately as `debi_created_at` / `debi_updated_at`.
 */
final class SubscriptionsTable {

	/** Bump when the schema changes so {@see maybe_install()} re-runs dbDelta. */
	public const DB_VERSION = '3';

	private const VERSION_OPTION = 'debipro_subscriptions_db_version';
	private const TABLE          = 'debipro_subscriptions';

	/**
	 * Fully-qualified table name on the current site's prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
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
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  wp_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id varchar(64) NOT NULL DEFAULT '',
  customer_name varchar(190) NOT NULL DEFAULT '',
  customer_email varchar(190) NOT NULL DEFAULT '',
  amount decimal(12,2) NOT NULL DEFAULT 0,
  currency varchar(8) NOT NULL DEFAULT '',
  description varchar(255) NOT NULL DEFAULT '',
  status varchar(32) NOT NULL DEFAULT '',
  total_count int DEFAULT NULL,
  interval_unit varchar(16) NOT NULL DEFAULT '',
  interval_count int NOT NULL DEFAULT 0,
  day_of_month smallint NOT NULL DEFAULT 0,
  day_of_week smallint DEFAULT NULL,
  auto_retries_max_attempts int DEFAULT NULL,
  livemode tinyint(1) NOT NULL DEFAULT 0,
  payment_method_id varchar(64) NOT NULL DEFAULT '',
  payment_method_type varchar(32) NOT NULL DEFAULT '',
  card_brand varchar(32) NOT NULL DEFAULT '',
  card_last4 varchar(8) NOT NULL DEFAULT '',
  start_date datetime DEFAULT NULL,
  first_date datetime DEFAULT NULL,
  upcoming_dates text DEFAULT NULL,
  debi_created_at datetime DEFAULT NULL,
  debi_updated_at datetime DEFAULT NULL,
  created_at datetime NULL DEFAULT NULL,
  updated_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (subscription_id),
  KEY user_id (wp_user_id),
  KEY product_id (product_id),
  KEY status (status)
) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Insert or update a subscription row. On update, identity columns
	 * (product_id, wp_user_id, customer_id) are only overwritten when a non-empty
	 * value is supplied — so a sparse webhook never clobbers the product / user
	 * learned at checkout — while every descriptive column is refreshed from the
	 * supplied row so the mirror tracks live Debi state.
	 *
	 * @param array<string, mixed> $row Typically {@see row_from_resource()} output.
	 */
	public static function upsert( array $row ): void {
		global $wpdb;

		$id = (string) ( $row['subscription_id'] ?? '' );
		if ( '' === $id ) {
			return;
		}

		$table = self::table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// Fetch the current status alongside existence: a row with status '' still
		// exists, so distinguish "no row" (null) from "row with empty status".
		$current_status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT status FROM {$table} WHERE subscription_id = %s", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$exists         = null !== $current_status;

		if ( $exists ) {
			$data = array( 'updated_at' => $now );
			foreach ( self::descriptive_columns() as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					$data[ $col ] = self::normalize_column( $col, $row[ $col ] );
				}
			}
			// Preserve the status trail: later refreshes (webhook/reconcile)
			// overwrite the row, so log every transition — e.g. a create-time
			// `paused`/`incomplete` flipping to `active` — before it's lost.
			if ( array_key_exists( 'status', $data ) ) {
				$new_status = (string) $data['status'];
				if ( '' !== $new_status && $new_status !== (string) $current_status ) {
					self::log(
						sprintf(
							'Subscription %s status changed: %s -> %s',
							$id,
							'' === (string) $current_status ? '(none)' : (string) $current_status,
							$new_status
						)
					);
				}
			}
			// Identity columns: keep the value learned at checkout unless a real one arrives.
			if ( ! empty( $row['customer_id'] ) ) {
				$data['customer_id'] = (string) $row['customer_id'];
			}
			if ( ! empty( $row['product_id'] ) ) {
				$data['product_id'] = (int) $row['product_id'];
			}
			if ( ! empty( $row['wp_user_id'] ) ) {
				$data['wp_user_id'] = (int) $row['wp_user_id'];
			}
			$wpdb->update( $table, $data, array( 'subscription_id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$insert = array(
			'subscription_id' => $id,
			'product_id'      => (int) ( $row['product_id'] ?? 0 ),
			'wp_user_id'      => (int) ( $row['wp_user_id'] ?? 0 ),
			'customer_id'     => (string) ( $row['customer_id'] ?? '' ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);
		foreach ( self::descriptive_columns() as $col ) {
			$insert[ $col ] = self::normalize_column( $col, $row[ $col ] ?? null );
		}

		$wpdb->insert( $table, $insert ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Record the status the mirror was first seen with (the create-time value
		// reported by Debi), so the initial state is never only transient.
		self::log( sprintf( 'Subscription %s mirrored with status %s', $id, (string) ( $insert['status'] ?? '' ) ) );
	}

	/**
	 * Info-level log to the shared `debipro` WooCommerce log source. Best-effort:
	 * silently a no-op when WooCommerce's logger is unavailable.
	 */
	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, array( 'source' => 'debipro' ) );
		}
	}

	/**
	 * Coerce a value to the shape its column expects. Nullable columns keep NULL
	 * (rather than being cast to 0/''), so "open-ended" and "weekly-only" fields
	 * read as absent instead of a misleading zero.
	 *
	 * @param string $col
	 * @param mixed  $value
	 * @return mixed
	 */
	private static function normalize_column( string $col, $value ) {
		$nullable_int = array( 'total_count', 'day_of_week', 'auto_retries_max_attempts' );
		$nullable_dt  = array( 'start_date', 'first_date', 'debi_created_at', 'debi_updated_at' );

		if ( in_array( $col, $nullable_int, true ) ) {
			return null === $value ? null : (int) $value;
		}
		if ( in_array( $col, $nullable_dt, true ) || 'upcoming_dates' === $col ) {
			return ( null === $value || '' === $value ) ? null : (string) $value;
		}
		if ( 'amount' === $col ) {
			return (float) $value;
		}
		if ( in_array( $col, array( 'interval_count', 'day_of_month', 'livemode' ), true ) ) {
			return (int) $value;
		}
		return (string) $value;
	}

	/**
	 * Subscription ids to reconcile against live Debi, oldest `updated_at` first
	 * (stalest rows benefit most under a `--limit`). Scoped to the current site,
	 * since the table is per-site and the CLI already runs in a site context.
	 *
	 * @param int $limit Max ids to return.
	 * @return array<int,string>
	 */
	public static function reconcile_ids( int $limit = 500 ): array {
		global $wpdb;

		$table = self::table_name();
		$limit = max( 1, $limit );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT subscription_id FROM {$table} ORDER BY updated_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			)
		);

		$out = array();
		if ( is_array( $ids ) ) {
			foreach ( $ids as $id ) {
				$id = (string) $id;
				if ( '' !== $id ) {
					$out[] = $id;
				}
			}
		}

		return $out;
	}

	/**
	 * Build an upsert row from a Debi subscription resource, mapping the whole
	 * subscription shape (schedule, customer, payment method, Debi timestamps) and
	 * reading the ids we stored in its metadata at checkout.
	 *
	 * @return array<string, mixed>
	 */
	public static function row_from_resource( Subscription $sub ): array {
		$md       = $sub->metadata ?? null;
		$customer = $sub->customer ?? null;
		$pm       = $sub->payment_method ?? null;
		$card     = is_object( $pm ) && isset( $pm->card ) ? $pm->card : null;

		$customer_id = '';
		if ( is_object( $customer ) && isset( $customer->id ) ) {
			$customer_id = (string) $customer->id;
		} elseif ( is_string( $customer ) ) {
			$customer_id = $customer;
		}

		$upcoming = isset( $sub->upcoming_dates ) && is_array( $sub->upcoming_dates )
			? array_values( array_map( 'strval', $sub->upcoming_dates ) )
			: array();

		return array(
			'subscription_id'           => isset( $sub->id ) ? (string) $sub->id : '',
			'product_id'                => self::md_int( $md, 'product_id' ),
			'wp_user_id'                => self::md_int( $md, 'wp_user_id' ),
			'customer_id'               => $customer_id,
			'customer_name'             => self::obj_str( $customer, 'name' ),
			'customer_email'            => self::obj_str( $customer, 'email' ),
			'amount'                    => isset( $sub->amount ) ? (float) $sub->amount : 0.0,
			'currency'                  => isset( $sub->currency ) ? (string) $sub->currency : '',
			'description'               => isset( $sub->description ) ? (string) $sub->description : '',
			'status'                    => isset( $sub->status ) ? (string) $sub->status : '',
			'total_count'               => isset( $sub->count ) && null !== $sub->count ? (int) $sub->count : null,
			'interval_unit'             => isset( $sub->interval_unit ) ? (string) $sub->interval_unit : '',
			'interval_count'            => isset( $sub->interval ) ? (int) $sub->interval : 0,
			'day_of_month'              => isset( $sub->day_of_month ) ? (int) $sub->day_of_month : 0,
			'day_of_week'               => isset( $sub->day_of_week ) && null !== $sub->day_of_week ? (int) $sub->day_of_week : null,
			'auto_retries_max_attempts' => isset( $sub->auto_retries_max_attempts ) && null !== $sub->auto_retries_max_attempts ? (int) $sub->auto_retries_max_attempts : null,
			'livemode'                  => ! empty( $sub->livemode ) ? 1 : 0,
			'payment_method_id'         => self::obj_str( $pm, 'id' ),
			'payment_method_type'       => self::obj_str( $pm, 'type' ),
			'card_brand'                => self::card_brand( $card ),
			'card_last4'                => self::card_last4( $card ),
			'start_date'                => self::to_mysql_dt( $sub->start_date ?? null ),
			'first_date'                => self::to_mysql_dt( $sub->first_date ?? null ),
			'upcoming_dates'            => array() === $upcoming ? null : wp_json_encode( $upcoming ),
			'debi_created_at'           => self::to_mysql_dt( $sub->created_at ?? null ),
			'debi_updated_at'           => self::to_mysql_dt( $sub->updated_at ?? null ),
		);
	}

	/**
	 * Columns that carry Debi-derived descriptive state, updated on every sync
	 * (unlike the identity columns, which are never clobbered by empties).
	 *
	 * @return array<int,string>
	 */
	private static function descriptive_columns(): array {
		return array(
			'customer_name',
			'customer_email',
			'amount',
			'currency',
			'description',
			'status',
			'total_count',
			'interval_unit',
			'interval_count',
			'day_of_month',
			'day_of_week',
			'auto_retries_max_attempts',
			'livemode',
			'payment_method_id',
			'payment_method_type',
			'card_brand',
			'card_last4',
			'start_date',
			'first_date',
			'upcoming_dates',
			'debi_created_at',
			'debi_updated_at',
		);
	}

	/**
	 * Count mirror rows on the current site, optionally filtered by exact status.
	 *
	 * @param string $status Exact status, or '' for all.
	 * @return int
	 */
	public static function count_rows( string $status = '' ): int {
		global $wpdb;
		$table = self::table_name();

		if ( '' === $status ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			)
		);
	}

	/**
	 * A page of mirror rows on the current site for the admin list screen.
	 *
	 * `$orderby` is whitelisted and `$order` normalized, so the (necessarily
	 * interpolated) ORDER BY clause can never carry caller input.
	 *
	 * @param int    $per_page Rows per page (1..200).
	 * @param int    $offset   Zero-based row offset.
	 * @param string $status   Exact status filter, or '' for all.
	 * @param string $orderby  A whitelisted column (see $allowed); defaults to updated_at.
	 * @param string $order    ASC|DESC.
	 * @return array<int,array<string,mixed>>
	 */
	public static function paged_rows(
		int $per_page = 20,
		int $offset = 0,
		string $status = '',
		string $orderby = 'updated_at',
		string $order = 'DESC'
	): array {
		global $wpdb;
		$table    = self::table_name();
		$per_page = min( 200, max( 1, $per_page ) );
		$offset   = max( 0, $offset );

		$allowed = array(
			'updated_at',
			'created_at',
			'amount',
			'status',
			'subscription_id',
			'currency',
			'interval_unit',
			'start_date',
			'first_date',
			'debi_created_at',
			'debi_updated_at',
			'livemode',
		);
		if ( ! in_array( $orderby, $allowed, true ) ) {
			$orderby = 'updated_at';
		}
		$order = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		$where  = '';
		$params = array();
		if ( '' !== $status ) {
			$where    = ' WHERE status = %s';
			$params[] = $status;
		}
		$params[] = $per_page;
		$params[] = $offset;

		$sql = "SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, $params ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sql built from whitelisted identifiers with %s/%d placeholders.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Row counts grouped by status on the current site (for list-table views).
	 *
	 * @return array<string,int> status => count.
	 */
	public static function status_counts(): array {
		global $wpdb;
		$table = self::table_name();

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$out[ (string) ( $r['status'] ?? '' ) ] = (int) ( $r['c'] ?? 0 );
			}
		}

		return $out;
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

	/**
	 * Read a string property off an expanded Debi sub-object (customer / payment
	 * method / card), tolerating null.
	 *
	 * @param mixed  $obj
	 * @param string $key
	 */
	private static function obj_str( $obj, string $key ): string {
		if ( is_object( $obj ) && isset( $obj->$key ) && is_scalar( $obj->$key ) ) {
			return (string) $obj->$key;
		}
		return '';
	}

	/**
	 * The card's last four digits, under whichever key Debi uses (`last4` in the
	 * card object; some payloads expose `last_four`).
	 *
	 * @param mixed $card
	 */
	/**
	 * The card's display brand. Debi exposes the network as both a machine value
	 * (`network` = "visa") and a display name (`name` = "Visa"); prefer the latter.
	 */
	private static function card_brand( $card ): string {
		foreach ( array( 'name', 'network', 'brand' ) as $key ) {
			$value = self::obj_str( $card, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * The card's last four digits. Debi returns `last_four_digits`; the other keys
	 * are tolerated so older payloads keep mapping.
	 */
	private static function card_last4( $card ): string {
		foreach ( array( 'last_four_digits', 'last4', 'last_four' ) as $key ) {
			$value = self::obj_str( $card, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Normalize a Debi date/datetime string (ISO 8601 or date-only) to MySQL
	 * `Y-m-d H:i:s` in UTC, or null when absent/unparseable.
	 *
	 * @param mixed $value
	 */
	private static function to_mysql_dt( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		$ts = strtotime( $value );
		return false === $ts ? null : gmdate( 'Y-m-d H:i:s', $ts );
	}
}
