<?php

declare(strict_types=1);

namespace Debi\HttpClient;

use Debi\Exception\TransportException;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface as Psr18Client;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Default transport: wraps any PSR-18 client found in the host application
 * (auto-discovered via `php-http/discovery`) and applies a small, safe retry
 * policy. Users can supply their own PSR-18 client, request factory, and
 * stream factory via constructor arguments to override the discovery.
 *
 * Retry policy
 * ------------
 * - Up to {@see DefaultClient::$maxRetries} retries on transport failures,
 *   HTTP 429, and HTTP 5xx, using exponential backoff with jitter.
 * - GET, HEAD, PUT, and DELETE are always considered idempotent and retried.
 * - POST is retried **only** if the request carries an `Idempotency-Key`
 *   header — otherwise we never silently double-charge a customer.
 */
final class DefaultClient implements ClientInterface
{
    public const DEFAULT_MAX_RETRIES = 2;
    public const DEFAULT_INITIAL_BACKOFF_MS = 500;
    public const DEFAULT_MAX_BACKOFF_MS = 4_000;

    private Psr18Client $http;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;
    private int $maxRetries;
    private int $initialBackoffMs;
    private int $maxBackoffMs;
    /** @var callable(int): void */
    private $sleeper;

    /**
     * @param array{
     *     http_client?: Psr18Client,
     *     request_factory?: RequestFactoryInterface,
     *     stream_factory?: StreamFactoryInterface,
     *     max_retries?: int,
     *     initial_backoff_ms?: int,
     *     max_backoff_ms?: int,
     *     sleeper?: callable(int): void,
     * } $config
     */
    public function __construct(array $config = [])
    {
        $this->http = $config['http_client'] ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $config['request_factory'] ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $config['stream_factory'] ?? Psr17FactoryDiscovery::findStreamFactory();
        $this->maxRetries = max(0, $config['max_retries'] ?? self::DEFAULT_MAX_RETRIES);
        $this->initialBackoffMs = max(0, $config['initial_backoff_ms'] ?? self::DEFAULT_INITIAL_BACKOFF_MS);
        $this->maxBackoffMs = max(0, $config['max_backoff_ms'] ?? self::DEFAULT_MAX_BACKOFF_MS);
        // The `sleeper` seam exists so tests can assert what we *would* sleep
        // for without actually blocking wall-clock time. Production code paths
        // never set it; the default delegates to PHP's `usleep`.
        $this->sleeper = $config['sleeper'] ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    public function send(string $method, string $url, array $headers, ?string $body): Response
    {
        $isIdempotent = $this->isRetryable($method, $headers);

        $attempt = 0;
        $lastException = null;
        while (true) {
            try {
                $response = $this->dispatch($method, $url, $headers, $body);

                if ($isIdempotent && $this->shouldRetryStatus($response->status) && $attempt < $this->maxRetries) {
                    $this->sleepForRetry($attempt, $response);
                    $attempt++;
                    continue;
                }
                return $response;
            } catch (TransportException $e) {
                $lastException = $e;
                if ($isIdempotent && $attempt < $this->maxRetries) {
                    $this->sleepForRetry($attempt, null);
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * @param array<string,string> $headers
     */
    private function dispatch(string $method, string $url, array $headers, ?string $body): Response
    {
        $request = $this->requestFactory->createRequest($method, $url);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        try {
            $psrResponse = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new TransportException(
                'HTTP transport error contacting Debi: ' . $e->getMessage(),
                0,
                $e,
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $flatHeaders = [];
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $flatHeaders[$name] = implode(', ', $values);
        }

        return new Response(
            status: $psrResponse->getStatusCode(),
            body: (string) $psrResponse->getBody(),
            headers: $flatHeaders,
        );
    }

    /**
     * @param array<string,string> $headers
     */
    private function isRetryable(string $method, array $headers): bool
    {
        $upper = strtoupper($method);
        if (in_array($upper, ['GET', 'HEAD', 'PUT', 'DELETE'], true)) {
            return true;
        }
        if ($upper === 'POST') {
            foreach ($headers as $k => $_v) {
                if (strcasecmp($k, 'Idempotency-Key') === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function sleepForRetry(int $attempt, ?Response $response): void
    {
        // HTTP header names are case-insensitive (RFC 7230). PSR-7's
        // `getHeaders()` preserves whatever casing the upstream server used,
        // so we must do a case-insensitive lookup or we will silently miss a
        // server-supplied `retry-after` hint and fall back to plain backoff.
        $retryAfter = null;
        if ($response !== null) {
            foreach ($response->headers as $name => $value) {
                if (strcasecmp($name, 'Retry-After') === 0) {
                    $retryAfter = $value;
                    break;
                }
            }
        }
        if (is_string($retryAfter) && ctype_digit($retryAfter)) {
            ($this->sleeper)(((int) $retryAfter) * 1_000_000);
            return;
        }

        $backoff = min($this->maxBackoffMs, $this->initialBackoffMs * (2 ** $attempt));
        $jitter = random_int(0, (int) ($backoff / 2));
        ($this->sleeper)(($backoff + $jitter) * 1_000);
    }
}
