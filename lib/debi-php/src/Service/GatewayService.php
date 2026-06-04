<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\RequestOptions;
use Debi\Resource\Gateway;

/**
 * Operations on `/v1/gateways`.
 */
final class GatewayService extends AbstractService
{
    private const BASE = '/v1/gateways';

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function all(array $params = [], array|RequestOptions|null $opts = null): Collection
    {
        return $this->requestCollection(self::BASE, $params, $opts);
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function enable(string $id, array|RequestOptions|null $opts = null): Gateway
    {
        /** @var Gateway $obj */
        $obj = $this->customAction('enable', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function disable(string $id, array|RequestOptions|null $opts = null): Gateway
    {
        /** @var Gateway $obj */
        $obj = $this->customAction('disable', self::BASE, $id, [], $opts);
        return $obj;
    }
}
