<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A snapshot of a state change inside Debi, delivered via webhooks or polled
 * from `/v1/events`. The `data.object` property carries the affected resource
 * at the time of the event.
 *
 * Sample id: `EV1rRDBDOEJM`.
 *
 * @property string  $id
 * @property string  $object
 * @property bool    $livemode
 * @property string  $type
 * @property string  $resource
 * @property ?string $resource_id
 * @property array   $data
 * @property string  $created_at
 * @property ?string $delivered_at
 */
final class Event extends ApiResource
{
    public const OBJECT_NAME = 'event';
}
