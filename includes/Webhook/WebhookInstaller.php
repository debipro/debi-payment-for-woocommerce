<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Webhook;

use Debi\DebiClient;
use Debi\Resource\WebhookEndpoint;
use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Registers (or reuses) the Debi webhook endpoint for this site.
 *
 * Lets the admin wire up webhooks in one click instead of copy-pasting the URL
 * and signing secret from the Debi dashboard: we look for an endpoint already
 * pointing at this site's URL and, if none exists, create one subscribed to the
 * payment lifecycle events the gateway acts on. Existing endpoints are updated
 * when their enabled_events set is stale.
 */
final class WebhookInstaller {

	/** Events the gateway needs delivered to drive payment projection. */
	public const EVENTS = array(
		'payment.created',
		'payment.updated',
		'payment.retrying',
		'payment.cancelled',
	);

	/**
	 * Ensure an endpoint for $url exists in the account behind $secret_key.
	 *
	 * @param string $secret_key Debi secret key (sk_test_… / sk_live_…).
	 * @param string $url        This site's webhook URL.
	 * @return array{created: bool, updated: bool, id: string, secret: string}
	 * @throws \Debi\Exception\ExceptionInterface On an API/transport failure.
	 */
	public static function ensure( string $secret_key, string $url ): array {
		$client = DebiClientFactory::create( $secret_key );

		$existing = self::find_by_url( $client, $url );
		if ( null !== $existing ) {
			$updated = self::sync_events( $client, $existing );
			return array(
				'created' => false,
				'updated' => $updated,
				'id'      => isset( $existing->id ) ? (string) $existing->id : '',
				'secret'  => isset( $existing->secret ) ? (string) $existing->secret : '',
			);
		}

		$created = $client->webhookEndpoints->create(
			array(
				'url'            => $url,
				'enabled_events' => self::EVENTS,
			)
		);

		return array(
			'created' => true,
			'updated' => false,
			'id'      => isset( $created->id ) ? (string) $created->id : '',
			'secret'  => isset( $created->secret ) ? (string) $created->secret : '',
		);
	}

	/**
	 * @return WebhookEndpoint|null
	 */
	private static function find_by_url( DebiClient $client, string $url ) {
		foreach ( $client->webhookEndpoints->all( array( 'limit' => 100 ) )->autoPagingIterator() as $endpoint ) {
			if ( $endpoint instanceof WebhookEndpoint && isset( $endpoint->url ) && (string) $endpoint->url === $url ) {
				return $endpoint;
			}
		}
		return null;
	}

	/**
	 * Update enabled_events when the stored set does not match what we need.
	 */
	private static function sync_events( DebiClient $client, WebhookEndpoint $endpoint ): bool {
		$id = isset( $endpoint->id ) ? (string) $endpoint->id : '';
		if ( '' === $id ) {
			return false;
		}

		$current = isset( $endpoint->enabled_events ) && is_array( $endpoint->enabled_events )
			? array_values( array_map( 'strval', $endpoint->enabled_events ) )
			: array();
		sort( $current );
		$needed = self::EVENTS;
		$sorted = $needed;
		sort( $sorted );

		if ( $current === $sorted ) {
			return false;
		}

		$client->webhookEndpoints->update(
			$id,
			array( 'enabled_events' => $needed )
		);
		return true;
	}
}
