<?php

declare(strict_types=1);

namespace Debi\Exception;

/**
 * Thrown when the HTTP request never produced a response — network failure,
 * DNS resolution error, TLS handshake failure, socket timeout, etc.
 *
 * The request did not complete; it is generally safe to retry idempotent
 * requests or POSTs that included an `Idempotency-Key`.
 */
class TransportException extends \RuntimeException implements ExceptionInterface {}
