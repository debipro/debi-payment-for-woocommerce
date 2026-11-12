<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

/**
 * Per-site mutex for webhook order routing (MySQL GET_LOCK).
 *
 * Debi can deliver several events for one payment at nearly the same time. Finding
 * the payment's order and creating it when missing must not interleave, or each
 * delivery creates its own order. A named MySQL lock is shared by every PHP worker
 * on the database, unlike a transient or object-cache flag.
 */
final class SyncLock {

	public const TIMEOUT_SECONDS = 30;

	private string $name;

	public function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * Lock scoped to the current site's tables (multisite-safe) and database.
	 */
	public static function for_current_blog(): self {
		global $wpdb;
		$prefix = isset( $wpdb ) && is_object( $wpdb ) ? (string) $wpdb->prefix : '';
		$db     = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
		// MySQL caps lock names at 64 characters.
		return new self( 'debipro_sync_' . md5( $db . '|' . $prefix ) );
	}

	public function name(): string {
		return $this->name;
	}

	/**
	 * Run $callback while holding the lock.
	 *
	 * @param callable():string $callback
	 * @return string|null The callback's result, or null when the lock was not acquired in time.
	 */
	public function run( callable $callback, int $timeout = self::TIMEOUT_SECONDS ): ?string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return $callback();
		}

		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->name, $timeout ) );
		if ( '1' !== (string) $acquired ) {
			return null;
		}

		try {
			return $callback();
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) );
		}
	}
}
