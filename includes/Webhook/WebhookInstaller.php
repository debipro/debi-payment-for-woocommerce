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
 * subscription lifecycle events the gateway acts on.
 */
final class WebhookInstaller {

	/** Events the gateway needs delivered to drive order status. */
	public const EVENTS = array( 'subscription.cancelled', 'subscription.finished' );

	/**
	 * Ensure an endpoint for $url exists in the account behind $secret_key.
	 *
	 * @param string $secret_key Debi secret key (sk_test_… / sk_live_…).
	 * @param string $url        This site's webhook URL.
	 * @return array{created: bool, id: string, secret: string}
	 * @throws \Debi\Exception\ExceptionInterface On an API/transport failure.
	 */
	public static function ensure( string $secret_key, string $url ): array {
		$client = DebiClientFactory::create( $secret_key );

		$existing = self::find_by_url( $client, $url );
		if ( null !== $existing ) {
			return array(
				'created' => false,
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
			'id'      => isset( $created->id ) ? (string) $created->id : '',
			'secret'  => isset( $created->secret ) ? (string) $created->secret : '',
		);
	}

	/**
	 * Return the first registered endpoint whose URL matches, scanning every page.
	 *
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
}
