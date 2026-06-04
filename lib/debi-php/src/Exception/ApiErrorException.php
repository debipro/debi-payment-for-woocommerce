<?php

declare(strict_types=1);

namespace Debi\Exception;

use Debi\Util\CaseInsensitiveArray;

/**
 * Base class for any error returned by the Debi API (4xx or 5xx).
 *
 * Subclasses are chosen by HTTP status. User code can catch this class to
 * handle any API error generically, or catch a more specific subclass for
 * targeted handling.
 */
class ApiErrorException extends \RuntimeException implements ExceptionInterface
{
    /**
     * @param array<int|string,mixed> $jsonBody
     * @param array<string,string>    $validationErrors
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly string $httpBody,
        public readonly CaseInsensitiveArray $httpHeaders,
        public readonly array $jsonBody,
        public readonly ?string $errorCode = null,
        public readonly ?string $requestId = null,
        public readonly array $validationErrors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Construct the right concrete subclass for the given HTTP status, deriving
     * the human-readable message and (when present) the error code, request id,
     * and per-field validation errors from the response body.
     *
     * @param array<int|string,mixed>|null $body  decoded JSON body, or null if empty/invalid
     * @param array<string,string>         $headers
     */
    public static function fromResponse(int $status, ?array $body, array $headers): self
    {
        $headersCi = new CaseInsensitiveArray($headers);
        $body ??= [];

        $message = is_string($body['message'] ?? null) && $body['message'] !== ''
            ? $body['message']
            : self::defaultMessageFor($status);

        $code = is_string($body['code'] ?? null) ? $body['code'] : null;
        // The SDK does not generate or send a request id of its own (Stripe-
        // style: that is a server-side concern). The Debi API does not echo
        // one back today either, but if/when it starts returning one — under
        // any of the common spellings — we surface it so users can quote it
        // in support tickets without having to read response headers manually.
        $requestId = $headersCi['x-request-id'] ?? $headersCi['request-id'] ?? null;

        $validationErrors = [];
        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $field => $msgs) {
                if (!is_string($field)) {
                    continue;
                }
                if (is_array($msgs)) {
                    $validationErrors[$field] = implode(' ', array_map(strval(...), $msgs));
                } elseif (is_scalar($msgs)) {
                    $validationErrors[$field] = (string) $msgs;
                }
            }
        }

        $class = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status === 400 || $status === 422 => InvalidRequestException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };

        return new $class(
            message: $message,
            httpStatus: $status,
            httpBody: $body === [] ? '' : (json_encode($body) ?: ''),
            httpHeaders: $headersCi,
            jsonBody: $body,
            errorCode: $code,
            requestId: is_string($requestId) ? $requestId : null,
            validationErrors: $validationErrors,
        );
    }

    private static function defaultMessageFor(int $status): string
    {
        return match (true) {
            $status === 401 => 'Authentication failed. Check your API key.',
            $status === 403 => 'Permission denied for this resource.',
            $status === 404 => 'Resource not found.',
            $status === 409 => 'Request conflicts with the current state of the resource.',
            $status === 422 => 'The request was well-formed but failed validation.',
            $status === 429 => 'Too many requests; you are being rate limited.',
            $status >= 500 => 'Debi API returned a server error.',
            default => 'Debi API returned an error.',
        };
    }
}
