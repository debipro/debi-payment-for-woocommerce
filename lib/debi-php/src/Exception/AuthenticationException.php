<?php

declare(strict_types=1);

namespace Debi\Exception;

/** Thrown on HTTP 401 — the API key is missing, malformed, or invalid. */
class AuthenticationException extends ApiErrorException {}
