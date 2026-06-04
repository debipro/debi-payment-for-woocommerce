<?php

declare(strict_types=1);

namespace Debi\HttpClient;

use Debi\Exception\TransportException;

/**
 * Internal HTTP contract used by {@see \Debi\ApiRequestor}.
 *
 * Library users do NOT implement this interface; they configure transport by
 * passing a PSR-18 client to {@see DefaultClient}. The interface exists so
 * tests can mock the network and so the SDK can layer retry / telemetry logic
 * in one well-defined place.
 *
 * @internal
 */
interface ClientInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException when no HTTP response was produced.
     */
    public function send(string $method, string $url, array $headers, ?string $body): Response;
}
