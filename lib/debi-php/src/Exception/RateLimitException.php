<?php

declare(strict_types=1);

namespace Debi\Exception;

/**
 * Thrown on HTTP 429. Consult `Retry-After` on {@see ApiErrorException::$httpHeaders}
 * to decide how long to wait before retrying.
 */
class RateLimitException extends ApiErrorException
{
    public function retryAfter(): ?int
    {
        $h = $this->httpHeaders['retry-after'] ?? null;
        return is_string($h) && ctype_digit($h) ? (int) $h : null;
    }
}
