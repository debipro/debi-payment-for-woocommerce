<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\RequestOptions;
use Debi\Resource\WebhookEndpoint;

/**
 * Operations on `/v1/webhooks`.
 */
final class WebhookEndpointService extends AbstractService
{
    private const BASE = '/v1/webhooks';

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function all(array $params = [], array|RequestOptions|null $opts = null): Collection
    {
        return $this->requestCollection(self::BASE, $params, $opts);
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): WebhookEndpoint
    {
        /** @var WebhookEndpoint $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): WebhookEndpoint
    {
        /** @var WebhookEndpoint $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function update(string $id, array $params, array|RequestOptions|null $opts = null): WebhookEndpoint
    {
        /** @var WebhookEndpoint $obj */
        $obj = $this->request('PUT', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function delete(string $id, array|RequestOptions|null $opts = null): void
    {
        $this->request('DELETE', self::BASE . '/' . $id, [], $opts);
    }
}
