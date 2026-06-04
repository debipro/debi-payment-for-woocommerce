<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A shareable payment link.
 *
 * Sample id: `LKYeoQ4WbDe9xdRq7j`.
 *
 * @property string  $id
 * @property string  $uuid
 * @property string  $object
 * @property bool    $livemode
 * @property string  $kind
 * @property string  $title
 * @property ?string $body
 * @property ?string $button_text
 * @property ?string $name_text
 * @property ?string $success_url
 * @property ?array  $extra_fields
 * @property ?array  $extra_fields_customer
 * @property ?array  $options
 * @property ?array  $metadata
 * @property string  $created_at
 * @property string  $updated_at
 */
final class Link extends ApiResource
{
    public const OBJECT_NAME = 'link';
}
