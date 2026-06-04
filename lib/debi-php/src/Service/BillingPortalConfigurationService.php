<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\RequestOptions;
use Debi\Resource\BillingPortalConfiguration;

/**
 * Operations on `/v1/billing_portal/configurations`.
 *
 * Configurations describe what a Billing Portal Session looks and behaves
 * like (which features are enabled, branding, default return URL, etc.).
 * They are a separate resource from sessions so a single configuration can
 * be reused across many sessions.
 *
 * Spec reference: openapi/paths/billing_portal@configurations.yaml,
 *                 openapi/paths/billing_portal@configurations@{id}.yaml
 */
final class BillingPortalConfigurationService extends AbstractService
{
    private const BASE = '/v1/billing_portal/configurations';

    /**
     * List configurations. Supports the `active` and `is_default` filters in
     * addition to the standard cursor pagination parameters.
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function all(array $params = [], array|RequestOptions|null $opts = null): Collection
    {
        return $this->requestCollection(self::BASE, $params, $opts);
    }

    /**
     * Create a new configuration. `features` (all four flags) and
     * `login_page.enabled` are required by the API.
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): BillingPortalConfiguration
    {
        /** @var BillingPortalConfiguration $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): BillingPortalConfiguration
    {
        /** @var BillingPortalConfiguration $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * Update a configuration. Like `create`, `features` and `login_page.enabled`
     * are always required even for partial updates — this mirrors the API
     * contract and keeps the SDK from silently dropping required fields.
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function update(string $id, array $params, array|RequestOptions|null $opts = null): BillingPortalConfiguration
    {
        /** @var BillingPortalConfiguration $obj */
        $obj = $this->request('PUT', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }
}
