<?php

declare(strict_types=1);

namespace DebiPro\Infrastructure;

use Debi\DebiClient;
use DebiPro\Infrastructure\Http\WpDebiHttpClient;

/**
 * Builds a configured {@see DebiClient} from the gateway settings.
 *
 * Single source of truth for credentials + environment selection: the secret
 * key (sk_test_… / sk_live_…) determines both authentication and which API base
 * the SDK talks to, inferred from the prefix — there is no separate sandbox flag.
 * The WordPress HTTP transport is injected so the SDK never pulls in its PSR-18
 * default client.
 */
final class DebiClientFactory {

	private const GATEWAY_OPTION = 'woocommerce_debipro_settings';

	/**
	 * Build a client for the given (or stored) secret key.
	 *
	 * @param string|null $secret_key Explicit key; when null, read from gateway settings.
	 * @param int         $timeout    HTTP timeout in seconds.
	 * @throws \RuntimeException When no secret key is configured.
	 */
	public static function create( ?string $secret_key = null, int $timeout = 30 ): DebiClient {
		$key = null === $secret_key ? self::stored_secret_key() : trim( $secret_key );

		if ( '' === $key ) {
			throw new \RuntimeException( 'Debi secret key is not configured.' );
		}

		return new DebiClient(
			array(
				'api_key'     => $key,
				'api_base'    => self::is_sandbox( $key ) ? DebiClient::DEFAULT_SANDBOX_BASE : DebiClient::DEFAULT_API_BASE,
				'http_client' => new WpDebiHttpClient( $timeout ),
			)
		);
	}

	/**
	 * The secret key stored in the WooCommerce gateway settings.
	 */
	public static function stored_secret_key(): string {
		$settings = get_option( self::GATEWAY_OPTION, array() );
		if ( ! is_array( $settings ) ) {
			return '';
		}
		return trim( (string) ( $settings['secret_key'] ?? '' ) );
	}

	/**
	 * Whether a key targets the sandbox environment (sk_test_… prefix).
	 */
	public static function is_sandbox( string $key ): bool {
		return 0 === strncmp( $key, 'sk_test_', 8 );
	}
}
