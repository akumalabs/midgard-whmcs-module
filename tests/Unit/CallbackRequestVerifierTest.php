<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\CallbackRequestVerifier;
use PHPUnit\Framework\TestCase;

/**
 * Pure verification of inbound build-completed callbacks — no WHMCS
 * bootstrap required (contract f2-c2: HMAC + ±300s window, hash_equals).
 *
 * @covers \MidgardWhmcs\CallbackRequestVerifier
 */
final class CallbackRequestVerifierTest extends TestCase
{
    private static function secret(): string
    {
        return 'ab' . str_repeat('0', 60);
    }

    private function signedHeaders(
        string $secret,
        string $body,
        int $timestamp,
        ?string $signatureOverride = null
    ): array {
        $timestampStr = (string) $timestamp;
        $signature = $signatureOverride
            ?? ('sha256=' . hash_hmac('sha256', $timestampStr . '.' . $body, $secret));

        return [
            'X-Midgard-Event' => 'server.build.completed',
            'X-Midgard-Event-Version' => '1',
            'X-Midgard-Delivery' => 'delivery-1',
            'X-Midgard-Timestamp' => $timestampStr,
            'X-Midgard-Signature' => $signature,
        ];
    }

    public function test_valid_signature_and_fresh_timestamp_passes(): void
    {
        $body = json_encode([
            'event' => 'server.build.completed',
            'version' => 1,
            'delivery_id' => 'd1',
            'timestamp' => 1750000000,
            'attempt' => 1,
            'data' => ['server_id' => 42],
        ]);

        $headers = $this->signedHeaders(self::secret(), $body, 1750000100);
        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1750000100);

        $this->assertTrue($result['ok'], (string) $result['error']);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000000);

        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000400);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['code']);
        $this->assertSame('stale timestamp', $result['error']);
    }

    public function test_timestamp_beyond_future_window_is_rejected(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000400);

        // 400s in the future relative to "now" → outside the ±300s window.
        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame('stale timestamp', $result['error']);
    }

    public function test_timestamp_at_window_edge_is_accepted(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000300);

        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000000);

        $this->assertTrue($result['ok'], (string) $result['error']);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000000, 'sha256=' . str_repeat('0', 64));

        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['code']);
        $this->assertSame('invalid signature', $result['error']);
    }

    public function test_signature_over_tampered_body_fails(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000000);

        $tampered = str_replace('1', '2', $body);
        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $tampered, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid signature', $result['error']);
    }

    public function test_wrong_secret_fails(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders('cd'.str_pad('', 60, '1'), $body, 1000000000);

        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid signature', $result['error']);
    }

    public function test_missing_signature_header_fails(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000000);
        unset($headers['X-Midgard-Signature']);

        $result = CallbackRequestVerifier::verify(self::secret(), $headers, $body, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame('missing signature', $result['error']);
    }

    public function test_header_names_are_case_and_prefix_insensitive(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $signature = 'sha256=' . hash_hmac('sha256', '1000000000.' . $body, self::secret());

        // $_SERVER style keys.
        $serverStyle = [
            'HTTP_X_MIDGARD_TIMESTAMP' => '1000000000',
            'HTTP_X_MIDGARD_SIGNATURE' => $signature,
        ];
        $this->assertTrue(
            CallbackRequestVerifier::verify(self::secret(), $serverStyle, $body, 1000000000)['ok']
        );

        // Lowercase names, no sha256 prefix (getallheaders variants).
        $lowercase = [
            'x-midgard-timestamp' => '1000000000',
            'x-midgard-signature' => hash_hmac('sha256', '1000000000.' . $body, self::secret()),
        ];
        $this->assertTrue(
            CallbackRequestVerifier::verify(self::secret(), $lowercase, $body, 1000000000)['ok']
        );
    }

    public function test_missing_or_empty_secret_fails_closed(): void
    {
        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $headers = $this->signedHeaders(self::secret(), $body, 1000000000);

        $result = CallbackRequestVerifier::verify('', $headers, $body, 1000000000);

        $this->assertFalse($result['ok']);
        $this->assertSame(401, $result['code']);
    }

    public function test_compute_signature_is_deterministic(): void
    {
        $a = CallbackRequestVerifier::computeSignature('1000', 'body', self::secret());
        $b = CallbackRequestVerifier::computeSignature('1000', 'body', self::secret());

        $this->assertSame($a, $b);
        $this->assertSame(
            hash_hmac('sha256', '1000.body', self::secret()),
            $a
        );
    }

    public function test_parse_envelope_accepts_valid_payload(): void
    {
        $body = json_encode([
            'event' => 'server.build.completed',
            'version' => 1,
            'delivery_id' => 'd9',
            'timestamp' => 1000,
            'attempt' => 1,
            'data' => [
                'server_id' => 77,
                'hostname' => 'silver-horizon',
                'status' => 'ready',
                'primary_ipv4' => '10.0.0.5',
                'primary_ipv6' => 'fd00::5',
            ],
        ]);

        $parsed = CallbackRequestVerifier::parseEnvelope($body);

        $this->assertTrue($parsed['ok']);
        $this->assertSame(77, (int) $parsed['data']['server_id']);
        $this->assertSame('silver-horizon', (string) $parsed['data']['hostname']);
    }

    public function test_parse_envelope_rejects_wrong_event_and_bad_json(): void
    {
        $this->assertFalse(CallbackRequestVerifier::parseEnvelope('not json')['ok']);
        $this->assertFalse(
            CallbackRequestVerifier::parseEnvelope('{"event":"server.deleted","data":{"server_id":1}}')['ok']
        );
        $this->assertFalse(
            CallbackRequestVerifier::parseEnvelope('{"event":"server.build.completed"}')['ok'],
            'missing data object must fail'
        );
        $this->assertFalse(
            CallbackRequestVerifier::parseEnvelope('{"event":"server.build.completed","data":{"server_id":0}}')['ok']
        );
    }
}
