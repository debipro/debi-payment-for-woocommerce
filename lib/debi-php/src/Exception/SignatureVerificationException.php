<?php

declare(strict_types=1);

namespace Debi\Exception;

/**
 * Thrown by {@see \Debi\Webhook::constructEvent()} when the signature header
 * is malformed, the HMAC does not match, or the timestamp is outside tolerance.
 *
 * Treat any instance of this exception as a hostile request and reject it.
 */
class SignatureVerificationException extends \RuntimeException implements ExceptionInterface {}
