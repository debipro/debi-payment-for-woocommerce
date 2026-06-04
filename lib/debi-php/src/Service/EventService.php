<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\RequestOptions;
use Debi\Resource\Event;

/**
 * Operations on `/v1/events`.
 */
final class EventService extends AbstractService
{
    private const BASE = '/v1/events';

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
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): Event
    {
        /** @var Event $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }
}
