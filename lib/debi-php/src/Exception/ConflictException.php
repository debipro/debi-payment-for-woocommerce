<?php

declare(strict_types=1);

namespace Debi\Exception;

/** Thrown on HTTP 409 — the request conflicts with the current state of the resource. */
class ConflictException extends ApiErrorException {}
