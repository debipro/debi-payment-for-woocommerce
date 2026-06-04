<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\Collection;
use Debi\DebiObject;
use Debi\RequestOptions;
use Debi\Resource\Payment;

/**
 * Operations on `/v1/payments`.
 *
 * Spec reference: openapi/paths/payments*.yaml
 */
final class PaymentService extends AbstractService
{
    private const BASE = '/v1/payments';

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
    public function retrieve(string $id, array $params = [], array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->request('GET', self::BASE . '/' . $id, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function create(array $params, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->request('POST', self::BASE, $params, $opts);
        return $obj;
    }

    /**
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function update(string $id, array $params, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
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
    public function confirm(string $id, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->customAction('confirm', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function cancel(string $id, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->customAction('cancel', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function retry(string $id, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->customAction('retry', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function stopAutoRetrying(string $id, array|RequestOptions|null $opts = null): Payment
    {
        /** @var Payment $obj */
        $obj = $this->customAction('stop_auto_retrying', self::BASE, $id, [], $opts);
        return $obj;
    }

    /**
     * Retrieve the bank transaction matched to a payment.
     *
     * **BETA endpoint.** The wire shape may change without notice. Returns
     * a generic {@see DebiObject} (the API response is `{ id, object: "bank_transaction", ... }`
     * which intentionally has no registered resource class so that BETA shape
     * changes do not break SDK builds).
     *
     * Spec reference: openapi/paths/payments@{id}@transaction.yaml
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function transaction(string $id, array $params = [], array|RequestOptions|null $opts = null): DebiObject
    {
        return $this->subResource('GET', self::BASE, $id, 'transaction', $params, $opts);
    }

    /**
     * List candidate bank transactions for manual reconciliation of a payment.
     *
     * **BETA endpoint.** The response wraps a `data` array but uses
     * `object: "list"` rather than the cursor-paginated envelope — returned
     * as a {@see DebiObject} (not a {@see Collection}).
     *
     * Spec reference: openapi/paths/payments@{id}@transaction@match_candidates.yaml
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function transactionMatchCandidates(string $id, array $params = [], array|RequestOptions|null $opts = null): DebiObject
    {
        return $this->subResource('GET', self::BASE, $id, 'transaction/match_candidates', $params, $opts);
    }

    /**
     * Manually match a payment to a specific bank transaction.
     *
     * **BETA endpoint.** Body must include `remote_transaction_id`; `notes` is
     * optional. The response is a custom envelope, not a standard resource.
     *
     * Spec reference: openapi/paths/payments@{id}@transaction@match.yaml
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    public function transactionMatch(string $id, array $params, array|RequestOptions|null $opts = null): DebiObject
    {
        return $this->subResource('POST', self::BASE, $id, 'transaction/match', $params, $opts);
    }
}
