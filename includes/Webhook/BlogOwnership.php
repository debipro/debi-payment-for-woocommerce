<?php
/**
 * Which site of a multisite network a Debi resource belongs to.
 *
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

namespace DebiPro\Webhook;

/**
 * Several sites may share one Debi account, so every site receives every webhook of that
 * account. Resources created by this plugin (and by integrations that follow its metadata
 * contract) carry the owning site in `metadata.blog_id`; a resource stamped for another site
 * must not create or update orders here, and must not stay in this site's subscriptions
 * mirror — e.g. after a subscription is re-attributed to another site's product.
 *
 * Resources without `blog_id` (created outside the plugin, older ones) are not foreign: they
 * keep the previous behavior. On a single-site install the current blog id is 1.
 */
final class BlogOwnership {

	/**
	 * The site a charge belongs to. The subscription's metadata wins over the payment's: it is
	 * the one re-stamped when a subscription is re-attributed, while a payment may carry a
	 * copy taken when it was created.
	 *
	 * @param mixed $subscription_metadata Subscription metadata (object or array) or null.
	 * @param mixed $payment_metadata      Payment metadata (object or array) or null.
	 * @return int Stamped blog id, 0 when none.
	 */
	public static function stamped_blog_id( $subscription_metadata, $payment_metadata = null ): int {
		$from_subscription = self::int_from( $subscription_metadata, 'blog_id' );
		if ( $from_subscription > 0 ) {
			return $from_subscription;
		}
		return self::int_from( $payment_metadata, 'blog_id' );
	}

	/**
	 * Whether a resource stamped for `$stamped_blog_id` belongs to another site.
	 */
	public static function is_foreign( int $stamped_blog_id, int $current_blog_id ): bool {
		return $stamped_blog_id > 0 && $stamped_blog_id !== $current_blog_id;
	}

	public static function current_blog_id(): int {
		return (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
	}

	/**
	 * @param mixed $metadata Metadata object or array.
	 */
	private static function int_from( $metadata, string $key ): int {
		if ( is_object( $metadata ) && isset( $metadata->$key ) && is_scalar( $metadata->$key ) ) {
			return (int) $metadata->$key;
		}
		if ( is_array( $metadata ) && isset( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
			return (int) $metadata[ $key ];
		}
		return 0;
	}
}
