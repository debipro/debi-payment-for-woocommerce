<?php

declare(strict_types=1);

namespace Debi;

/**
 * Per-request overrides.
 *
 * Designed as a forever-extensible bag: adding a new option must never be a
 * breaking change. Construction is private — always go through {@see parse()}
 * so user code can keep passing plain arrays.
 *
 * @psalm-immutable
 */
final class RequestOptions
{
    public function __construct(
        public readonly ?string $apiKey = null,
        public readonly ?string $apiVersion = null,
        public readonly ?string $idempotencyKey = null,
        /** @var array<string, string> */
        public readonly array $headers = [],
    ) {}

    /**
     * Accepts either an existing {@see RequestOptions} instance, an associative
     * array (the user-facing form), or null. Always returns a {@see RequestOptions}.
     *
     * @param self|array<string,mixed>|null $opts
     */
    public static function parse(self|array|null $opts): self
    {
        if ($opts instanceof self) {
            return $opts;
        }
        if ($opts === null) {
            return new self();
        }

        $headers = $opts['headers'] ?? [];
        if (!is_array($headers)) {
            throw new \InvalidArgumentException('RequestOptions `headers` must be an array.');
        }

        /** @var array<string,string> $stringHeaders */
        $stringHeaders = [];
        foreach ($headers as $k => $v) {
            if (!is_string($k) || !is_scalar($v)) {
                throw new \InvalidArgumentException('RequestOptions `headers` must map string to scalar.');
            }
            $stringHeaders[$k] = (string) $v;
        }

        return new self(
            apiKey: self::stringOrNull($opts, 'api_key'),
            apiVersion: self::stringOrNull($opts, 'api_version'),
            idempotencyKey: self::stringOrNull($opts, 'idempotency_key'),
            headers: $stringHeaders,
        );
    }

    /**
     * @param array<string,mixed> $opts
     */
    private static function stringOrNull(array $opts, string $key): ?string
    {
        if (!array_key_exists($key, $opts)) {
            return null;
        }
        $v = $opts[$key];
        if ($v === null) {
            return null;
        }
        if (!is_string($v)) {
            throw new \InvalidArgumentException("RequestOptions `{$key}` must be a string."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }
        return $v;
    }
}
