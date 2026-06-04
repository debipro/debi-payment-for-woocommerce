<?php

declare(strict_types=1);

namespace Debi\Exception;

/** Thrown on HTTP 404 — the resource does not exist (or is not visible to this key). */
class NotFoundException extends ApiErrorException {}
