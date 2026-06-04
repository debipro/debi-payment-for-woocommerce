<?php

declare(strict_types=1);

namespace Debi\HttpClient;

/**
 * Minimal HTTP response value object used by the SDK's internal call path.
 *
 * Decouples {@see \Debi\ApiRequestor} from PSR-7 message objects so that
 * tests and alternative transports do not need to construct full PSR-7
 * responses to talk to the requestor.
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {}
}
