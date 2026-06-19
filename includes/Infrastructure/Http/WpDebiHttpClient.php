<?php
/**
 * @package Debi_Payment_For_WooCommerce
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace DebiPro\Infrastructure\Http;

use Debi\Exception\TransportException;
use Debi\HttpClient\ClientInterface;
use Debi\HttpClient\Response;

/**
 * Debi SDK transport backed by WordPress' HTTP API (`wp_remote_request`).
 *
 * The Debi PHP SDK lets callers inject a `Debi\HttpClient\ClientInterface`
 * directly (the simple `send()` contract used by its own test stubs). Supplying
 * this WP-native implementation means the SDK never reaches for its PSR-18
 * `DefaultClient`, so we avoid vendoring the entire PSR-17/18 + php-http/discovery
 * dependency tree and every outbound call still flows through the WordPress
 * transport stack (proxy constants, WP_HTTP_BLOCK_EXTERNAL, pre_http_request
 * filters, managed-host SSL pinning, etc.).
 */
final class WpDebiHttpClient implements ClientInterface {

	private int $timeout;

	public function __construct( int $timeout = 30 ) {
		$this->timeout = $timeout;
	}

	/**
	 * @param array<string, string> $headers
	 */
	public function send( string $method, string $url, array $headers, ?string $body ): Response {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			throw new TransportException( 'wp_remote_request() is unavailable; WpDebiHttpClient must run inside WordPress.' );
		}

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => null === $body ? '' : $body,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'httpversion' => '1.1',
			'data_format' => 'body',
		);

		$result = wp_remote_request( $url, $args );
		if ( is_wp_error( $result ) ) {
			throw new TransportException( 'WordPress HTTP transport error: ' . $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$status    = (int) wp_remote_retrieve_response_code( $result );
		$resp_body = (string) wp_remote_retrieve_body( $result );

		$flat_headers = array();
		$headers_obj  = wp_remote_retrieve_headers( $result );
		if ( is_array( $headers_obj ) ) {
			foreach ( $headers_obj as $name => $value ) {
				$flat_headers[ (string) $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		} elseif ( is_object( $headers_obj ) && method_exists( $headers_obj, 'getAll' ) ) {
			foreach ( $headers_obj->getAll() as $name => $value ) {
				$flat_headers[ (string) $name ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		return new Response( $status, $resp_body, $flat_headers );
	}
}
