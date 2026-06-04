<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\RequestOptions;
use Debi\Resource\Session;

/**
 * Operations on `/v1/sessions`.
 */
final class SessionService extends AbstractService
{
    private const BASE = '/v1/sessions';

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): Session
    {
        /** @var Session $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): Session
    {
        /** @var Session $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }
}
