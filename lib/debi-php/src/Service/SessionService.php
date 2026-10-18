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
     * Create a hosted checkout session.
     *
     * `kind` is one of `payment`, `subscription` or `mandate` (there is no
     * `installment` kind). `installments` and `max_installments` apply only
     * when `kind` is `payment`. `metadata` is stored on the session and
     * propagates to the produced payment/subscription/mandate; `customer_metadata`
     * lands on the created customer. Redirect the customer to the returned
     * session's `public_uri`.
     *
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
