<?php

declare(strict_types=1);

namespace Debi;

use Debi\Exception\SignatureVerificationException;
use Debi\Resource\Event;

/**
 * Webhook signature verification.
 *
 * Debi signs each delivered event with an HMAC-SHA256 of the payload, sent in
 * the `Debi-Signature` header as `t=<timestamp>,v1=<sig>[,v1=<sig>...]`.
 * Multiple `v1=` signatures are supported so endpoint secrets can be rotated
 * by sending both the old and new signature for a short overlap period.
 *
 * Always pass the **raw, unmodified request body**. Any middleware that
 * re-encodes JSON, trims whitespace, or otherwise mutates the body will
 * break verification.
 */
final class Webhook
{
    /** Default tolerance for the timestamp check, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify the signature and parse the payload as an {@see Event}.
     *
     * @throws SignatureVerificationException on any verification failure.
     */
    public static function constructEvent(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): Event {
        self::verifySignature($payload, $sigHeader, $secret, $tolerance);

        try {
            $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SignatureVerificationException(
                'Webhook payload is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new SignatureVerificationException('Webhook payload must decode to a JSON object.');
        }

        return Event::constructFrom($decoded);
    }

    /**
     * Verify a webhook signature without parsing the payload. Useful when the
     * caller wants to record the raw bytes before constructing an Event.
     *
     * @throws SignatureVerificationException
     */
    public static function verifySignature(
        string $payload,
        string $sigHeader,
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): void {
        if ($secret === '') {
            throw new SignatureVerificationException('Webhook secret must not be empty.');
        }

        $parsed = self::parseHeader($sigHeader);
        $timestamp = $parsed['t'];
        $signatures = $parsed['v1'];

        $signedPayload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        $matched = false;
        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            throw new SignatureVerificationException(
                'No signatures in Debi-Signature header matched the expected signature.'
            );
        }

        if ($tolerance > 0) {
            $age = time() - (int) $timestamp;
            if ($age > $tolerance || $age < -$tolerance) {
                throw new SignatureVerificationException(
                    'Webhook timestamp is outside the tolerance window.'
                );
            }
        }
    }

    /**
     * @return array{t: string, v1: list<string>}
     */
    private static function parseHeader(string $header): array
    {
        if ($header === '') {
            throw new SignatureVerificationException('Debi-Signature header is empty.');
        }

        $timestamp = null;
        /** @var list<string> $signatures */
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            [$key, $value] = $kv;
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || !ctype_digit($timestamp)) {
            throw new SignatureVerificationException(
                'Debi-Signature header is missing a valid `t=<timestamp>` value.'
            );
        }
        if ($signatures === []) {
            throw new SignatureVerificationException(
                'Debi-Signature header is missing any `v1=<signature>` value.'
            );
        }

        return ['t' => $timestamp, 'v1' => $signatures];
    }
}
