<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A recurring subscription schedule for a customer.
 *
 * Sample id: `SBmQ6j9NWxblNv`.
 *
 * @property string  $id
 * @property string  $object
 * @property bool    $livemode
 * @property string  $status
 * @property float   $amount
 * @property string  $currency
 * @property ?string $description
 * @property string  $customer_id
 * @property ?string $mandate_id
 * @property string  $start_date
 * @property ?int    $count
 * @property ?array  $metadata
 * @property string  $created_at
 */
final class Subscription extends ApiResource
{
    public const OBJECT_NAME = 'subscription';
}
