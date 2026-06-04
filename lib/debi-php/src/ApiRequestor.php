<?php

declare(strict_types=1);

namespace Debi;

use Debi\Exception\ApiErrorException;
use Debi\HttpClient\ClientInterface;
use Debi\HttpClient\Response;
use Debi\Util\CaseInsensitiveArray;

/**
 * The only class in the SDK that constructs HTTP requests.
 *
 * Services hand it a method, path, and parameter array; it returns a decoded
 * JSON body plus headers/status. Everything cross-cutting — authentication,
 * version pinning, user-agent, idempotency, header overrides, status-to-
 * exception mapping — lives here, so no service ever has to think about it.
 *
 * @internal
 */
final class ApiRequestor
{
    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $apiBase,
        private readonly string $apiVersion,
    ) {}

    /**
     * @param array<int|string,mixed> $params
     *
     * @return array{0: array<int|string,mixed>, 1: array<string,string>, 2: int}
     */
    public function request(
        string $method,
        string $path,
        array $params = [],
        ?RequestOptions $opts = null,
    ): array {
        $opts ??= new RequestOptions();

        $url = $this->apiBase . $path;
        $headers = $this->buildHeaders($method, $opts);

        $body = null;
        if (strtoupper($method) === 'GET') {
            $flatParams = Util\Util::objectsToIds($params);
            if ($flatParams !== []) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($flatParams);
            }
        } elseif ($params !== []) {
            $headers['Content-Type'] = 'application/json';
            $body = $this->encodeJson(Util\Util::objectsToIds($params));
        }

        $response = $this->httpClient->send($method, $url, $headers, $body);

        return $this->interpretResponse($response);
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(string $method, RequestOptions $opts): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . ($opts->apiKey ?? $this->apiKey),
            // The Debi API does not currently read this header, but pinning a
            // version per SDK release is forward-compatible: when dated API
            // versioning is rolled out server-side, every existing SDK install
            // will keep behaving as it does today.
            'Debi-Version' => $opts->apiVersion ?? $this->apiVersion,
            'User-Agent' => Debi::userAgent(),
            'Accept' => 'application/json',
        ];

        if ($opts->idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $opts->idempotencyKey;
        }

        foreach ($opts->headers as $name => $value) {
            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private function encodeJson(array $value): string
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException(
                'Could not encode request body as JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        return $encoded;
    }

    /**
     * @return array{0: array<int|string,mixed>, 1: array<string,string>, 2: int}
     */
    private function interpretResponse(Response $response): array
    {
        $status = $response->status;
        $body = $response->body;

        $decoded = null;
        $jsonError = null;
        if ($body !== '') {
            try {
                $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $jsonError = $e;
            }
        }
        if (!is_array($decoded)) {
            $decoded = [];
        }

        // Treat any non-2xx as an error. The SDK deliberately does not follow
        // 3xx redirects: auto-following them could leak the Authorization header
        // across hosts (and a redirecting API is almost always a misconfigured
        // apiBase, which we want to surface loudly instead of silently fix).
        if ($status >= 300) {
            $payload = ($decoded === [] || $jsonError !== null)
                ? ['message' => $this->describeNonJsonResponse($status, $body, $response->headers)]
                : $decoded;

            throw ApiErrorException::fromResponse($status, $payload, $response->headers);
        }

        // 2xx with a non-empty body that fails to parse as JSON is a protocol
        // mismatch — the server claimed success but did not return a body the
        // SDK can use. Surfacing this as a typed error keeps misconfigurations
        // (proxies stripping bodies, middleware injecting HTML, an upstream
        // returning a redirect page with a 200) loud and localized instead of
        // showing up as missing fields somewhere downstream.
        if ($jsonError !== null) {
            throw ApiErrorException::fromResponse(
                $status,
                ['message' => $this->describeNonJsonResponse($status, $body, $response->headers)],
                $response->headers,
            );
        }

        // 2xx with an empty body is intentionally allowed: DELETE endpoints
        // commonly return 202/204 with no payload, and several Debi actions
        // (resetSecret, archive, etc.) also use this shape.
        return [$decoded, $response->headers, $status];
    }

    /**
     * @param array<string,string> $headers
     */
    private function describeNonJsonResponse(int $status, string $body, array $headers): string
    {
        if ($status >= 300 && $status < 400) {
            $ci = new CaseInsensitiveArray($headers);
            $location = $ci['location'] ?? null;
            if (is_string($location) && $location !== '') {
                return sprintf(
                    'Debi API returned an unfollowed %d redirect to %s. '
                    . 'Point DebiClient apiBase at the final URL (the SDK does not '
                    . 'auto-follow redirects to avoid leaking the Authorization header).',
                    $status,
                    $location,
                );
            }
            return sprintf('Debi API returned an unfollowed %d redirect.', $status);
        }

        return sprintf(
            'Debi API returned %d with a non-JSON body. Body preview: %s',
            $status,
            $this->bodyPreview($body),
        );
    }

    private function bodyPreview(string $body): string
    {
        $collapsed = preg_replace('/\s+/', ' ', trim($body)) ?? '';
        return strlen($collapsed) > 200 ? substr($collapsed, 0, 200) . '…' : $collapsed;
    }
}
