<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A refund of a previously created payment.
 *
 * Sample id: `RFljikas9Fa8`.
 *
 * @property string  $id
 * @property string  $object
 * @property bool    $livemode
 * @property float   $amount
 * @property string  $currency
 * @property string  $status
 * @property string  $payment_id
 * @property ?string $reason
 * @property ?array  $metadata
 * @property string  $created_at
 */
final class Refund extends ApiResource
{
    public const OBJECT_NAME = 'refund';
}
