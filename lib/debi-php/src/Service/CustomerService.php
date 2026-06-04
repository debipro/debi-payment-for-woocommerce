<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\RequestOptions;
use Debi\Resource\Customer;

/**
 * Operations on `/v1/customers`.
 */
final class CustomerService extends AbstractService
{
    private const BASE = '/v1/customers';

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
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): Customer
    {
        /** @var Customer $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): Customer
    {
        /** @var Customer $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function update(string $id, array $params, array|RequestOptions|null $opts = null): Customer
    {
        /** @var Customer $obj */
        $obj = $this->request('PUT', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function search(array $params, array|RequestOptions|null $opts = null): Collection
    {
        return $this->requestCollection(self::BASE . '/search', $params, $opts);
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function archive(string $id, array|RequestOptions|null $opts = null): Customer
    {
        /** @var Customer $obj */
        $obj = $this->customAction('archive', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function restore(string $id, array|RequestOptions|null $opts = null): Customer
    {
        /** @var Customer $obj */
        $obj = $this->customAction('restore', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function paymentMethods(string $id, array $params = [], array|RequestOptions|null $opts = null): Collection
    {
        return $this->requestCollection(self::BASE . '/' . $id . '/payment_methods', $params, $opts);
    }
}
