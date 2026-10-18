<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;
use Debi\DebiObject;

/**
 * A hosted page to process payments, subscriptions and mandates.
 *
 * Sample id: `SSmQ6j9NWxblNv`.
 *
 * The hosted page lives at `public_uri`; there is no `url`, `status` or
 * `expires_at` field on the wire. A finished session is one with a
 * `completed_at`.
 *
 * `metadata` passed to {@see \Debi\Service\SessionService::create()} is stored
 * on the session itself and propagates to the `payment`, `subscription` or
 * `mandate` the session produces (reachable at `resource`). `customer_metadata`
 * likewise lands on the created `Customer`.
 *
 * There is no `installment` session kind: `kind` is always `payment`,
 * `subscription` or `mandate`. Installments are a `payment` session that also
 * sets `installments` (fixed count) or `max_installments` (the customer picks
 * the count); both apply only when `kind` is `payment`.
 *
 * @property string      $id
 * @property string      $uuid
 * @property string      $object
 * @property ?string     $description
 * @property ?float      $amount
 * @property string      $kind                        one of `payment`, `subscription`, `mandate`
 * @property ?string     $customer_id
 * @property ?string     $customer_name
 * @property ?string     $customer_email
 * @property ?string     $customer_gateway_identifier
 * @property ?bool       $smart_merge
 * @property ?bool       $editable_amount
 * @property ?int        $installments                payment sessions only; fixed number of installments
 * @property ?int        $max_installments            payment sessions only; lets the customer pick the installment count
 * @property ?string     $interval_unit
 * @property ?int        $interval
 * @property ?int        $day_of_month
 * @property ?int        $day_of_week
 * @property ?int        $count
 * @property ?bool       $editable_count
 * @property ?string     $name_text
 * @property ?array      $extra_fields
 * @property ?array      $extra_fields_customer
 * @property string      $created_at
 * @property string      $updated_at
 * @property ?string     $completed_at
 * @property bool        $livemode
 * @property ?bool       $binary_mode
 * @property ?string     $payment_gateway_identifier
 * @property ?string[]   $payment_method_types
 * @property ?DebiObject $payment_method_options
 * @property DebiObject  $supported_payment_methods
 * @property string      $public_uri                  the hosted checkout URL
 * @property ?string     $success_url
 * @property ?DebiObject $next_action
 * @property ?DebiObject $resource                    the payment/subscription/mandate the session produced
 * @property ?string     $link_id
 * @property ?DebiObject $metadata                    key/value pairs stored on the session and copied onto the produced resource
 * @property DebiObject  $user                        branding of the organization that owns the session
 */
final class Session extends ApiResource
{
    public const OBJECT_NAME = 'session';
}
