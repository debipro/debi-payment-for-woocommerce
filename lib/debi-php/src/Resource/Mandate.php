<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A customer's authorization to debit a payment method.
 *
 * Sample id: `MAmQ6j9NWxblNv`.
 *
 * @property string  $id
 * @property string  $uuid
 * @property string  $object
 * @property bool    $livemode
 * @property string  $status
 * @property string  $customer_id
 * @property ?string $payment_method_id
 * @property ?array  $metadata
 * @property string  $created_at
 */
final class Mandate extends ApiResource
{
    public const OBJECT_NAME = 'mandate';
}
