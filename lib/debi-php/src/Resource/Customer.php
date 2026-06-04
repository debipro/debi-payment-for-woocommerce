<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A customer of your organization.
 *
 * Sample id: `CSjRZ5JqjAw0`.
 *
 * @property string      $id
 * @property string      $object
 * @property bool        $livemode
 * @property ?string     $name
 * @property ?string     $email
 * @property ?string     $mobile_number
 * @property ?string     $identification_type
 * @property ?string     $identification_number
 * @property ?string     $default_payment_method_id
 * @property ?string     $gateway_identifier
 * @property ?array      $metadata
 * @property string      $created_at
 * @property string      $updated_at
 * @property ?string     $deleted_at
 */
final class Customer extends ApiResource
{
    public const OBJECT_NAME = 'customer';
}
