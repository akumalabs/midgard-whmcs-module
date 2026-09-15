<?php

declare(strict_types=1);

namespace MidgardWhmcs;

/**
 * Pure verification/decoding of inbound Midgard build-completed callback
 * requests. Deliberately free of WHMCS bootstrap, DB and HTTP concerns so
 * unit tests can exercise it directly.
 *
 * Wire contract (f2-c2-contract.md):
 *   Envelope: {event:"server.build.completed", version:1, delivery_id,
 *             timestamp, attempt, data:{server_id, hostname, status,
 *             primary_ipv4, primary_ipv6}}
 *   Headers:  X-Midgard-Event, X-Midgard-Event-Version: 1,
 *             X-Midgard-Delivery, X-Midgard-Timestamp (unix),
 *             X-Midgard-Signature: "sha256=" . hash_hmac('sha256',
 *                                                     timestamp . "." . rawBody,
 *                                                     secret)
 */
final class CallbackRequestVerifier
{
    /** Allowed clock skew between the panel's X-Midgard-Timestamp and now. */
    public const TIMESTAMP_WINDOW = 300;

    public const EXPECTED_EVENT = 'server.build.completed';

    /**
     * Recompute the expected HMAC signature (hex, no prefix).
     */
    public static function computeSignature(string $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    }

    /**
     * Verify headers + timestamp window + HMAC against the install secret.
     *
     * @param string $secret 64-hex secret persisted by CallbackRegistrar.
     * @param array<string, string> $headers header name (case-insensitive
     *        keys are normalized internally) → value.
     * @param string $rawBody the RAW request body (php://input), not decoded.
     * @param int|null $now current unix time; defaults to time().
     * @return array{ok: bool, error: string, code: int}
     *         code is the HTTP status the caller must respond with on
     *         failure (401 for any verification failure, per contract).
     */
    public static function verify(
        string $secret,
        array $headers,
        string $rawBody,
        ?int $now = null
    ): array {
        if (trim($secret) === '') {
            return ['ok' => false, 'error' => 'callback not registered', 'code' => 401];
        }

        $normalized = self::normalizeHeaders($headers);
        $signatureHeader = self::stripSha256Prefix(
            (string) ($normalized['x-midgard-signature'] ?? '')
        );
        if ($signatureHeader === '') {
            return ['ok' => false, 'error' => 'missing signature', 'code' => 401];
        }

        $timestamp = trim((string) ($normalized['x-midgard-timestamp'] ?? ''));
        if ($timestamp === '' || ! preg_match('/^\d+$/', $timestamp)) {
            return ['ok' => false, 'error' => 'missing or invalid timestamp', 'code' => 401];
        }

        $timestampInt = (int) $timestamp;
        $current = $now ?? time();
        if (abs($current - $timestampInt) > self::TIMESTAMP_WINDOW) {
            return ['ok' => false, 'error' => 'stale timestamp', 'code' => 401];
        }

        $expected = self::computeSignature($timestamp, $rawBody, $secret);
        if (! hash_equals($expected, $signatureHeader)) {
            return ['ok' => false, 'error' => 'invalid signature', 'code' => 401];
        }

        return ['ok' => true, 'error' => '', 'code' => 200];
    }

    /**
     * Decode and structurally validate the envelope JSON.
     *
     * @param string $rawBody raw request body.
     * @return array{ok: bool, error: string, data: array<string, mixed>}
     */
    public static function parseEnvelope(string $rawBody): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            return ['ok' => false, 'error' => 'invalid JSON', 'data' => []];
        }

        $event = strtolower(trim((string) ($decoded['event'] ?? '')));
        if ($event !== self::EXPECTED_EVENT) {
            return ['ok' => false, 'error' => 'unexpected event', 'data' => []];
        }

        $data = $decoded['data'] ?? null;
        if (! is_array($data) || (int) ($data['server_id'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'missing server_id', 'data' => []];
        }

        return ['ok' => true, 'error' => '', 'data' => $data];
    }

    /**
     * Header collections may come from $_SERVER (HTTP_* keys) or getallheaders().
     * Normalize to lowercase names without the HTTP_ prefix.
     *
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (str_starts_with($name, 'http_')) {
                $name = substr($name, 5);
            }
            $name = str_replace('_', '-', $name);
            if (! isset($normalized[$name])) {
                $normalized[$name] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $normalized;
    }

    private static function stripSha256Prefix(string $signature): string
    {
        if (str_starts_with($signature, 'sha256=')) {
            return substr($signature, strlen('sha256='));
        }

        // The bare hex digest is accepted too: the prefix is part of the
        // wire format, but rejecting it twice over adds no security — the
        // HMAC comparison below is the actual gate.
        return $signature;
    }
}
