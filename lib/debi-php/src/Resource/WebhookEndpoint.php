<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A registered webhook delivery endpoint.
 *
 * The API returns the discriminator value `"object": "webhook"` for this
 * resource (despite the path being `/v1/webhooks`).
 *
 * @property string         $id
 * @property string         $object
 * @property string         $url
 * @property bool           $enabled
 * @property bool           $livemode
 * @property array<string>  $enabled_events
 * @property ?string        $secret
 * @property int            $failed_lately_count
 * @property string         $created_at
 * @property string         $updated_at
 */
final class WebhookEndpoint extends ApiResource
{
    public const OBJECT_NAME = 'webhook';
}
