<?php

declare(strict_types=1);

namespace Debi\Exception;

/**
 * Thrown on HTTP 400 / 422 — the request is malformed or failed validation.
 *
 * Inspect {@see ApiErrorException::$validationErrors} for per-field messages
 * and {@see ApiErrorException::$errorCode} for a machine-readable code (when present).
 */
class InvalidRequestException extends ApiErrorException {}
