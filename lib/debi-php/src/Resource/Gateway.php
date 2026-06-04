<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A configured upstream payment gateway.
 *
 * Sample id: `GWBZqKYEK7Y2`.
 *
 * @property string $id
 * @property string $object
 * @property string $name
 * @property bool   $livemode
 * @property bool   $enabled
 * @property ?int   $number
 * @property ?int   $code_length
 * @property ?string $approved_at
 * @property string $created_at
 */
final class Gateway extends ApiResource
{
    public const OBJECT_NAME = 'gateway';
}
