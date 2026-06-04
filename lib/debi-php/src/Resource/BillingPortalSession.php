<?php

declare(strict_types=1);

namespace Debi\Resource;

use Debi\ApiResource;

/**
 * A short-lived, hosted page that lets a customer manage their billing
 * details (payment methods, subscriptions, payment history) without the
 * integrating application having to build any of those flows itself.
 *
 * Sessions are write-only on the API: there is no retrieve endpoint, and
 * the page behind {@see $url} is meant to be opened once per session.
 * Always generate a fresh session right before redirecting; never persist
 * the {@see $url}.
 *
 * Spec reference: openapi/components/schemas/BillingPortalSession.yaml
 *
 * @property string  $id                              e.g. `BPS5Z25Agp708`
 * @property string  $object                          always `billing_portal.session`
 * @property string  $customer_id                     the Customer this session belongs to
 * @property string  $billing_portal_configuration_id configuration used to render the portal
 * @property string  $url                             hosted billing-portal URL to redirect to
 * @property ?string $return_url                      URL the portal sends the customer back to
 * @property bool    $livemode
 * @property string  $created_at
 * @property string  $updated_at
 */
final class BillingPortalSession extends ApiResource
{
    public const OBJECT_NAME = 'billing_portal.session';
}
