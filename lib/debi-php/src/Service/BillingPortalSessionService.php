<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\RequestOptions;
use Debi\Resource\BillingPortalSession;

/**
 * Operations on `/v1/billing_portal/sessions`.
 *
 * The API exposes a single endpoint here: `POST` to create. There is no
 * list, retrieve, update, or delete. Sessions are single-use bearers for
 * the hosted billing portal URL — generate one right before redirecting
 * the customer and discard it after.
 *
 *     $session = $debi->billingPortalSessions->create([
 *         'customer_id' => $customer->id,
 *         'return_url'  => 'https://app.example/account',
 *     ]);
 *     return redirect($session->url);
 *
 * Spec reference: openapi/paths/billing_portal@sessions.yaml
 */
final class BillingPortalSessionService extends AbstractService
{
    private const BASE = '/v1/billing_portal/sessions';

    /**
     * Create a new portal session for a customer. The returned object's `url`
     * field is the short-lived page to redirect the customer to.
     *
     * @param array<int|string,mixed>                 $params Required: `customer_id`.
     *                                                        Optional: `billing_portal_configuration_id`, `return_url`.
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): BillingPortalSession
    {
        /** @var BillingPortalSession $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }
}
