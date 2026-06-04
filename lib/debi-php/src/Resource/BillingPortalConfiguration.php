<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * Describes the functionality and appearance of a Billing Portal Session.
 * A merchant can have multiple configurations and pick which one to use
 * when creating a session; the first configuration created on the account
 * becomes the default automatically.
 *
 * The billing portal feature must be enabled on the account: a 404 on any
 * of these endpoints means the merchant needs to contact Debi support.
 *
 * Spec reference: openapi/components/schemas/BillingPortalConfiguration.yaml
 *
 * @property string                $id               e.g. `BPCqXz3a8YbE`
 * @property string                $object           always `billing_portal.configuration`
 * @property bool                  $active
 * @property bool                  $is_default
 * @property array<string,mixed>   $features
 * @property array<string,mixed>   $login_page
 * @property ?array<string,mixed>  $business_profile
 * @property ?string               $default_return_url
 * @property ?array<int,string>    $payment_method_types
 * @property ?array<string,mixed>  $payment_method_options
 * @property ?array<string,mixed>  $supported_payment_methods read-only
 * @property ?array<string,mixed>  $metadata
 * @property bool                  $livemode
 * @property string                $created_at
 * @property string                $updated_at
 */
final class BillingPortalConfiguration extends ApiResource
{
    public const OBJECT_NAME = 'billing_portal.configuration';
}
