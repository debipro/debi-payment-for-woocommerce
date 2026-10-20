<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Checkout;

use Debi\DebiClient;
use Debi\Resource\Session;
use DebiPro\Infrastructure\DebiClientFactory;

/**
 * Creates a Debi hosted-checkout Session for redirect-mode products.
 *
 * Unlike {@see SubscriptionCreator} (embedded: card tokenised on-site, then a
 * subscription created server-side), this builds a Debi Session and hands the
 * buyer its `public_uri`. The card is captured on Debi's hosted page, and the
 * produced payment/subscription is correlated back to the originating WooCommerce
 * order and product through the session `metadata`, which Debi propagates onto
 * the created resource.
 *
 * Step 3 covers `payment` and `installment` products (both are a `payment`
 * session; installments are expressed with the `installments` / `max_installments`
 * param). Subscription and mandate sessions arrive in later steps.
 */
final class DebiSessionCreator {

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
	 * Create a payment/installment session for a WooCommerce order.
	 *
	 * @param array{
	 *     order: \WC_Order,
	 *     amount: float,
	 *     installments: int|null,
	 *     max_installments: int|null,
	 *     editable_amount: bool,
	 *     description: string,
	 *     success_url: string,
	 *     product_id: int,
	 *     wp_user_id: int,
	 *     customer: array{name: string, email: string, identification_number?: string}
	 * } $args
	 * @return Session
	 * @throws \Debi\Exception\ExceptionInterface When Debi rejects the request.
	 * @throws \RuntimeException                  On configuration / empty-result errors.
	 */
	public static function create( array $args ): Session {
		$order           = $args['order'];
		$amount          = (float) ( $args['amount'] ?? 0 );
		$editable_amount = (bool) ( $args['editable_amount'] ?? false );
		$success_url     = (string) ( $args['success_url'] ?? '' );

		if ( '' === $success_url ) {
			throw new \RuntimeException( 'Missing success_url for Debi session.' );
		}
		if ( ! $editable_amount && $amount <= 0 ) {
			throw new \RuntimeException( 'Invalid session amount.' );
		}

		$client   = self::client();
		$blog_id  = self::blog_id();
		$order_id = (int) $order->get_id();

		$session = $client->sessions->create(
			self::build_params( $args ),
			array( 'idempotency_key' => sprintf( 'debipro-sess-%d-%d', $blog_id, $order_id ) )
		);

		if ( ! isset( $session->public_uri ) || '' === (string) $session->public_uri ) {
			throw new \RuntimeException( 'Debi did not return a session public_uri.' );
		}

		return $session;
	}

	/**
	 * Assemble the `sessions.create` params for a payment/installment session.
	 *
	 * Pure (no network); `create()` posts the result. Metadata (blog_id, order_id,
	 * product_id, wp_user_id) is what Debi propagates onto the produced resource so
	 * the webhook can correlate it back to this order and product.
	 *
	 * @param array{
	 *     order: \WC_Order,
	 *     amount: float,
	 *     installments?: int|null,
	 *     max_installments?: int|null,
	 *     editable_amount?: bool,
	 *     description?: string,
	 *     success_url: string,
	 *     product_id?: int,
	 *     wp_user_id?: int,
	 *     customer?: array{name?: string, email?: string, identification_number?: string}
	 * } $args
	 * @return array<string, mixed>
	 */
	public static function build_params( array $args ): array {
		$order            = $args['order'];
		$amount           = (float) ( $args['amount'] ?? 0 );
		$installments     = isset( $args['installments'] ) ? (int) $args['installments'] : 0;
		$max_installments = isset( $args['max_installments'] ) ? (int) $args['max_installments'] : 0;
		$editable_amount  = (bool) ( $args['editable_amount'] ?? false );
		$description      = (string) ( $args['description'] ?? '' );
		$success_url      = (string) ( $args['success_url'] ?? '' );
		$product_id       = (int) ( $args['product_id'] ?? 0 );
		$wp_user_id       = (int) ( $args['wp_user_id'] ?? 0 );
		$customer         = (array) ( $args['customer'] ?? array() );

		$metadata = array(
			'blog_id'    => (string) self::blog_id(),
			'order_id'   => (string) $order->get_id(),
			'product_id' => (string) $product_id,
		);
		if ( $wp_user_id > 0 ) {
			$metadata['wp_user_id'] = (string) $wp_user_id;
		}

		$params = array(
			'kind'           => 'payment',
			'success_url'    => $success_url,
			'amount'         => $amount,
			'description'    => $description,
			'customer_name'  => (string) ( $customer['name'] ?? '' ),
			'customer_email' => (string) ( $customer['email'] ?? '' ),
			'metadata'       => $metadata,
		);

		if ( $editable_amount ) {
			$params['editable_amount'] = true;
		}

		// Installments are payment-only. Fixed count wins over customer-chosen max.
		if ( $installments > 0 ) {
			$params['installments'] = $installments;
		} elseif ( $max_installments > 0 ) {
			$params['max_installments'] = $max_installments;
		}

		$identification = trim( (string) ( $customer['identification_number'] ?? '' ) );
		if ( '' !== $identification ) {
			$params['customer_identification_number'] = $identification;
		}

		return $params;
	}

	private static function blog_id(): int {
		return (int) ( function_exists( 'get_current_blog_id' ) ? get_current_blog_id() : 1 );
	}

	private static function client(): DebiClient {
		if ( null !== self::$client_factory ) {
			return ( self::$client_factory )();
		}
		return DebiClientFactory::create();
	}
}
