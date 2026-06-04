<?php

declare(strict_types=1);

namespace Debi\Exception;

/** Thrown on any HTTP 5xx — the server failed to fulfill an otherwise valid request. */
class ServerException extends ApiErrorException {}
