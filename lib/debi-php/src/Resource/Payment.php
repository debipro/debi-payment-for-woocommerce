<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A payment charged to a customer's payment method.
 *
 * Sample id: `PY8EJ1NdNwzD`.
 *
 * @property string  $id
 * @property string  $object
 * @property bool    $livemode
 * @property float   $amount
 * @property float   $amount_refunded
 * @property float   $amount_refundable
 * @property string  $currency
 * @property string  $status
 * @property ?string $description
 * @property ?string $response_message
 * @property ?string $rejection_code
 * @property ?string $provider_rejection_code
 * @property bool    $paid
 * @property bool    $retryable
 * @property bool    $refundable
 * @property ?string $customer_id
 * @property ?string $payment_method_id
 * @property ?string $mandate_id
 * @property ?array  $metadata
 * @property string  $charge_date
 * @property string  $created_at
 */
final class Payment extends ApiResource
{
    public const OBJECT_NAME = 'payment';
}
