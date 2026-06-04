<?php

declare(strict_types=1);

namespace Debi\Exception;

/**
 * Marker interface implemented by every exception this SDK throws.
 *
 * Allows user code to catch any SDK-originated failure with a single
 * `catch (\Debi\Exception\ExceptionInterface $e)`.
 */
interface ExceptionInterface extends \Throwable {}
