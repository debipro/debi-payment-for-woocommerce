<?php

declare(strict_types=1);

namespace Debi\Service;

use Debi\ApiRequestor;
use Debi\Collection;
use Debi\DebiObject;
use Debi\RequestOptions;
use Debi\Util\Util;

/**
 * Base class for all resource services. Provides the small set of helpers
 * every service needs (`request`, `requestCollection`, `customAction`) so
 * that concrete services contain only path strings and method signatures.
 */
abstract class AbstractService
{
    public function __construct(protected readonly ApiRequestor $requestor) {}

    /**
     * @param array<int|string,mixed>          $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    protected function request(
        string $method,
        string $path,
        array $params = [],
        array|RequestOptions|null $opts = null,
    ): DebiObject {
        $options = RequestOptions::parse($opts);
        [$body] = $this->requestor->request($method, $path, $params, $options);

        // 2xx with no payload (DELETE returning 204, several action endpoints,
        // archive/restore in some configurations). Return an empty DebiObject
        // so callers that ignore the return value (`delete()`) keep working
        // and callers that consume it do not have to special-case the shape.
        if ($body === []) {
            return new DebiObject();
        }

        // Single-resource success responses are wrapped in a `data` envelope.
        // Unwrap before hydration so callers see a clean Customer/Payment/etc.
        if (
            isset($body['data'])
            && is_array($body['data'])
            && !array_is_list($body['data'])
        ) {
            $body = $body['data'];
        }

        $result = Util::convertToObject($body);
        if (!$result instanceof DebiObject) {
            throw new \UnexpectedValueException('Debi API returned a non-object response.');
        }
        return $result;
    }

    /**
     * @param array<int|string,mixed>          $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    protected function requestCollection(
        string $path,
        array $params = [],
        array|RequestOptions|null $opts = null,
    ): Collection {
        $options = RequestOptions::parse($opts);
        [$body] = $this->requestor->request('GET', $path, $params, $options);

        if (!isset($body['data']) || !is_array($body['data'])) {
            // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \UnexpectedValueException(
                "Expected a list response from {$path} containing a `data` array."
            );
            // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $collection = Collection::fromList($body);
        $collection->setRequestParams($this->requestor, $path, $params, $options);
        return $collection;
    }

    /**
     * Invoke an `actions/{verb}` endpoint such as `/v1/payments/{id}/actions/cancel`.
     * Centralizing this avoids a one-off method-per-action in every service.
     *
     * @param array<int|string,mixed>          $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    protected function customAction(
        string $verb,
        string $basePath,
        string $id,
        array $params = [],
        array|RequestOptions|null $opts = null,
    ): DebiObject {
        return $this->request('POST', "{$basePath}/{$id}/actions/{$verb}", $params, $opts);
    }

    /**
     * Invoke a sub-resource verb that lives directly under the resource path,
     * with no `/actions/` prefix — e.g. `POST /v1/payment_methods/{id}/attach`
     * (not `/v1/payment_methods/{id}/actions/attach`). The Debi API uses both
     * conventions, with `/actions/` for stateful state-machine transitions and
     * plain sub-paths for relationship operations.
     *
     * @param array<int|string,mixed>                 $params
     * @param array<string,mixed>|RequestOptions|null $opts
     */
    protected function subResource(
        string $method,
        string $basePath,
        string $id,
        string $subPath,
        array $params = [],
        array|RequestOptions|null $opts = null,
    ): DebiObject {
        return $this->request($method, "{$basePath}/{$id}/{$subPath}", $params, $opts);
    }
}
