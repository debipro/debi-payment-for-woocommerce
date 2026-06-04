<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A report export job (CSV / Excel).
 *
 * @property string  $id
 * @property string  $object
 * @property string  $status
 * @property string  $resource
 * @property ?string $url
 * @property string  $created_at
 */
final class Export extends ApiResource
{
    public const OBJECT_NAME = 'export';
}
