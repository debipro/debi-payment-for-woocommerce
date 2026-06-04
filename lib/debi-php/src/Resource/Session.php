<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A hosted page to process payments, subscriptions and mandates.
 *
 * Sample id: `SSmQ6j9NWxblNv`.
 *
 * @property string  $id
 * @property string  $uuid
 * @property string  $object
 * @property bool    $livemode
 * @property string  $url
 * @property string  $status
 * @property ?float  $amount
 * @property ?string $description
 * @property ?string $customer_id
 * @property ?string $customer_name
 * @property ?array  $metadata
 * @property string  $created_at
 * @property ?string $expires_at
 */
final class Session extends ApiResource
{
    public const OBJECT_NAME = 'session';
}
