<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Subscription;

use Debi\DebiClient;
use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Perform a single-subscription Debi operation and sync the local mirror:
 *
 *  - {@see self::reconcile_one()} retrieves the subscription (GET) and upserts it.
 *  - {@see self::cancel_one()} cancels it (POST) and upserts the returned resource.
 *
 * Both back the admin list's per-row actions. The WP-CLI reconcile command keeps
 * its own loop so it can log each resource's status. The client factory is
 * overridable for unit tests, mirroring {@see \DebiPro\Webhook\OrderSync}.
 */
final class SubscriptionReconciler {

	/** @var callable():DebiClient|null */
	private static $client_factory = null;

	/**
	 * Override Debi client construction (unit tests).
	 *
	 * @param callable():DebiClient|null $factory
	 */
	public static function set_client_factory( $factory ): void {
		self::$client_factory = $factory;
	}

	/**
	 * Retrieve one subscription from Debi and upsert the local mirror.
	 *
	 * @param string $id Debi subscription id.
	 * @return string Result code: reconciled|missing_id|client_error|fetch_failed.
	 */
	public static function reconcile_one( string $id ): string {
		$id = trim( $id );
		if ( '' === $id ) {
			return 'missing_id';
		}

		try {
			$client = self::client();
		} catch ( \Throwable $e ) {
			return 'client_error';
		}

		try {
			$subscription = $client->subscriptions->retrieve( $id );
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'Reconcile fetch failed for %s: %s', $id, $e->getMessage() ) );
			return 'fetch_failed';
		}

		SubscriptionsTable::upsert( SubscriptionsTable::row_from_resource( $subscription ) );
		return 'reconciled';
	}

	/**
	 * Cancel one subscription in Debi and upsert the returned (cancelled) resource
	 * into the local mirror, so the status flips immediately without waiting for
	 * the subscription.cancelled webhook.
	 *
	 * @param string $id Debi subscription id.
	 * @return string Result code: cancelled|missing_id|client_error|cancel_failed.
	 */
	public static function cancel_one( string $id ): string {
		$id = trim( $id );
		if ( '' === $id ) {
			return 'missing_id';
		}

		try {
			$client = self::client();
		} catch ( \Throwable $e ) {
			return 'client_error';
		}

		try {
			$subscription = $client->subscriptions->cancel( $id );
		} catch ( \Throwable $e ) {
			self::log( sprintf( 'Cancel failed for %s: %s', $id, $e->getMessage() ) );
			return 'cancel_failed';
		}

		SubscriptionsTable::upsert( SubscriptionsTable::row_from_resource( $subscription ) );
		return 'cancelled';
	}

	/**
	 * Warn-level log to the shared `debipro` WooCommerce log source, so a failed
	 * row action leaves a diagnosable trail (the UI only shows a generic message).
	 */
	private static function log( string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning( $message, array( 'source' => 'debipro' ) );
		}
	}

	private static function client(): DebiClient {
		if ( null !== self::$client_factory ) {
			return ( self::$client_factory )();
		}
		return DebiClientFactory::create();
	}
}
