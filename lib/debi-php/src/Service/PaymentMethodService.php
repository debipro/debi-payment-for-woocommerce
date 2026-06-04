<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\DebiObject;
use Debi\RequestOptions;
use Debi\Resource\PaymentMethod;

/**
 * Operations on `/v1/payment_methods`.
 *
 * Spec reference: openapi/paths/payment_methods*.yaml
 */
final class PaymentMethodService extends AbstractService
{
    private const BASE = '/v1/payment_methods';

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
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): PaymentMethod
    {
        /** @var PaymentMethod $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): PaymentMethod
    {
        /** @var PaymentMethod $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
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
     * Attach a payment method to a customer.
     *
     * Wire shape: `POST /v1/payment_methods/{id}/attach` with body `{customer: "CS..."}`.
     * Returns `204 No Content`, so the SDK returns a typed but empty
     * {@see DebiObject} — callers usually discard the return value.
     *
     * @param string                                  $id         payment method id
     * @param string                                  $customerId customer id to attach to
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function attach(string $id, string $customerId, array|RequestOptions|null $opts = null): DebiObject
    {
        return $this->subResource(
            method: 'POST',
            basePath: self::BASE,
            id: $id,
            subPath: 'attach',
            params: ['customer' => $customerId],
            opts: $opts,
        );
    }

    /**
     * Detach a payment method from its customer.
     *
     * Wire shape: `POST /v1/payment_methods/{id}/detach`, no body, returns 204.
     *
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function detach(string $id, array|RequestOptions|null $opts = null): DebiObject
    {
        return $this->subResource(
            method: 'POST',
            basePath: self::BASE,
            id: $id,
            subPath: 'detach',
            opts: $opts,
        );
    }
}
