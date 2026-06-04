<?php

declare(strict_types=1);

namespace Debi\Exception;

/** Thrown on HTTP 403 — the key is valid but not authorized for this resource. */
class PermissionException extends ApiErrorException {}
