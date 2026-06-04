<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A customer's payment instrument (card, bank account, CBU, etc.).
 *
 * Sample id: `PMJODBMZdayP`.
 *
 * @property string  $id
 * @property string  $object
 * @property bool    $livemode
 * @property string  $type
 * @property ?array  $card
 * @property ?array  $cbu
 * @property ?string $customer_id
 * @property ?array  $metadata
 * @property string  $created_at
 * @property string  $updated_at
 */
final class PaymentMethod extends ApiResource
{
    public const OBJECT_NAME = 'payment_method';
}
