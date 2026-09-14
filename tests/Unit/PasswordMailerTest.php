<?php

declare(strict_types=1);

namespace MidgardWhmcs {
    if (! function_exists(__NAMESPACE__ . '\localAPI')) {
        /**
         * @param array<string, mixed> $values
         * @return array<string, mixed>
         */
        function localAPI(string $command, array $values): array
        {
            return \MidgardWhmcs\Tests\Unit\PasswordMailerLocalApiSpy::handle($command, $values);
        }
    }
}

namespace MidgardWhmcs\Tests\Unit {

    use MidgardWhmcs\MetadataStore;
    use MidgardWhmcs\PasswordMailer;
    use PHPUnit\Framework\TestCase;

    final class PasswordMailerLocalApiSpy
    {
        /** @var array<int, array<string, mixed>> */
        public static array $calls = [];

        /** @var array<int, array<string, mixed>> */
        public static array $responses = [];

        public static function reset(): void
        {
            self::$calls = [];
            self::$responses = [];
        }

        /**
         * @param array<string, mixed> $values
         * @return array<string, mixed>
         */
        public static function handle(string $command, array $values): array
        {
            self::$calls[] = [
                'command' => $command,
                'values' => $values,
            ];

            $index = count(self::$calls) - 1;
            return self::$responses[$index] ?? ['result' => 'success'];
        }
    }

    /**
     * Fake MetadataStore that allows tests to inject IP metadata
     * for credential rendering without touching the database.
     * Not final: individual tests override claimPasswordDispatch inline.
     */
    class FakeMetadataStore extends MetadataStore
    {
        /** @var array<int, array<string, mixed>> */
        public array $claims = [];

        /** @var array<int, array<string, mixed>> */
        public array $finalized = [];

        /** @var array<int, string> */
        public array $released = [];

        /** @var array<int, string> */
        public array $queued = [];

        /** @var array<string, mixed> */
        public array $meta = [
            'midgard_primary_ipv4' => '',
            'midgard_primary_ipv6' => '',
        ];

        /**
         * @param array<string, mixed> $data
         */
        public function setMeta(array $data): void
        {
            $this->meta = array_merge($this->meta, $data);
        }

        /**
         * @return array<string, mixed>
         */
        public function get(int $serviceId): array
        {
            return $this->meta;
        }

        public function upsert(int $serviceId, array $data): void
        {
            $this->meta = array_merge($this->meta, $data);
        }

        public function patchMeta(int $serviceId, array $data): void
        {
            // Mirror the real store's targeted-write semantics.
            foreach (['midgard_pending_password', 'midgard_welcome_template', 'midgard_password_email_sent_at'] as $key) {
                if (array_key_exists($key, $data)) {
                    $this->meta[$key] = (string) $data[$key];
                }
            }
        }

        public function claimPasswordDispatch(int $serviceId, string $serverUuid): ?string
        {
            $this->claims[] = [
                'service_id' => $serviceId,
                'server_uuid' => $serverUuid,
            ];

            return 'dispatch-hash';
        }

        public function queuePasswordDispatch(string $dispatchHash): void
        {
            $this->queued[] = $dispatchHash;
        }

        public function finalizePasswordDispatch(int $serviceId, string $dispatchHash): void
        {
            $this->finalized[] = [
                'service_id' => $serviceId,
                'dispatch_hash' => $dispatchHash,
            ];
        }

        public function releasePasswordDispatch(string $dispatchHash): void
        {
            $this->released[] = $dispatchHash;
        }
    }

    final class PasswordMailerTest extends TestCase
    {
        protected function setUp(): void
        {
            PasswordMailerLocalApiSpy::reset();
        }

        public function test_send_one_time_uses_service_id_first(): void
        {
            $store = new FakeMetadataStore();
            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'success'],
            ];

            PasswordMailer::sendOneTime([
                'serviceid' => 123,
                'userid' => 456,
            ], $store, 'server-uuid', 'SecretPass123!');

            $this->assertCount(1, PasswordMailerLocalApiSpy::$calls);
            $this->assertSame('SendEmail', PasswordMailerLocalApiSpy::$calls[0]['command']);
            $this->assertSame(123, PasswordMailerLocalApiSpy::$calls[0]['values']['id']);
            $this->assertCount(1, $store->finalized);
            $this->assertCount(0, $store->released);
            $this->assertSame('SecretPass123!', $this->extractVarFromCall(0, 'midgard_server_password'));
        }

        public function test_send_one_time_falls_back_to_client_id_when_service_attempt_fails(): void
        {
            $store = new FakeMetadataStore();
            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'error', 'message' => 'Template type mismatch'],
                ['result' => 'success'],
            ];

            PasswordMailer::sendOneTime([
                'serviceid' => 321,
                'userid' => 654,
            ], $store, 'server-uuid', 'AnotherPass456!');

            $this->assertCount(2, PasswordMailerLocalApiSpy::$calls);
            $this->assertSame(321, PasswordMailerLocalApiSpy::$calls[0]['values']['id']);
            $this->assertSame(654, PasswordMailerLocalApiSpy::$calls[1]['values']['id']);
            $this->assertCount(1, $store->finalized);
            $this->assertCount(0, $store->released);
            $this->assertSame('AnotherPass456!', $this->extractVarFromCall(1, 'midgard_server_password'));
        }

        public function test_send_one_time_releases_dispatch_when_all_attempts_fail(): void
        {
            $store = new FakeMetadataStore();
            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'error', 'message' => 'Service send failed'],
                ['result' => 'error', 'message' => 'Client send failed'],
            ];

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to send credentials email');

            try {
                PasswordMailer::sendOneTime([
                    'serviceid' => 111,
                    'userid' => 222,
                ], $store, 'server-uuid', 'FinalPass789!');
            } finally {
                $this->assertCount(2, PasswordMailerLocalApiSpy::$calls);
                $this->assertCount(0, $store->finalized);
                $this->assertCount(1, $store->released);
                $this->assertSame('dispatch-hash', $store->released[0]);
            }
        }

        public function test_send_one_time_injects_primary_ips_and_aliases(): void
        {
            $store = new FakeMetadataStore();
            $store->setMeta([
                'midgard_primary_ipv4' => '203.0.113.10',
                'midgard_primary_ipv6' => '2001:db8::1',
            ]);

            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'success'],
            ];

            PasswordMailer::sendOneTime([
                'serviceid' => 999,
                'userid' => 1000,
            ], $store, 'server-uuid', 'IpInjectionPass!');

            $this->assertSame('IpInjectionPass!', $this->extractVarFromCall(0, 'midgard_server_password'));
            $this->assertSame('IpInjectionPass!', $this->extractVarFromCall(0, 'service_password'));
            $this->assertSame('IpInjectionPass!', $this->extractVarFromCall(0, 'server_password'));
            $this->assertSame('203.0.113.10', $this->extractVarFromCall(0, 'midgard_primary_ipv4'));
            $this->assertSame('2001:db8::1', $this->extractVarFromCall(0, 'midgard_primary_ipv6'));
            $this->assertSame('203.0.113.10', $this->extractVarFromCall(0, 'service_dedicated_ip'));
        }

        public function test_send_one_time_dedicated_ip_falls_back_to_ipv6_when_no_ipv4(): void
        {
            $store = new FakeMetadataStore();
            $store->setMeta([
                'midgard_primary_ipv4' => '',
                'midgard_primary_ipv6' => '2001:db8::5',
            ]);

            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'success'],
            ];

            PasswordMailer::sendOneTime([
                'serviceid' => 432,
                'userid' => 765,
            ], $store, 'server-uuid', 'Ipv6OnlyPass!');

            $this->assertSame('2001:db8::5', $this->extractVarFromCall(0, 'service_dedicated_ip'));
        }

        /**
         * @return mixed
         */
        private function extractVarFromCall(int $callIndex, string $key)
        {
            $encodedVars = (string) (PasswordMailerLocalApiSpy::$calls[$callIndex]['values']['customvars'] ?? '');
            $decodedVars = @unserialize((string) base64_decode($encodedVars), ['allowed_classes' => false]);
            if (! is_array($decodedVars)) {
                return null;
            }

            return $decodedVars[$key] ?? null;
        }

        // ── Async queue path (PasswordMailer::queue + borrowed dispatch) ──

        public function test_queue_seals_password_and_marks_dispatch_queued(): void
        {
            $store = new FakeMetadataStore();

            PasswordMailer::queue(['serviceid' => 77, 'userid' => 88], $store, 'uuid-77', 'QueuedPass123!');

            $this->assertCount(1, $store->claims);
            $this->assertCount(1, $store->queued);
            $this->assertSame('dispatch-hash', $store->queued[0]);
            $this->assertSame('Midgard Provisioning Credentials', $store->meta['midgard_welcome_template'] ?? '');

            // encrypt()/decrypt() are undefined in the test environment, so
            // the seal must fall back to the reversible 'plain:' prefix.
            $sealed = (string) ($store->meta['midgard_pending_password'] ?? '');
            $this->assertStringStartsWith('plain:', $sealed);
            $this->assertSame('QueuedPass123!', base64_decode(substr($sealed, 6), true));
        }

        public function test_queue_is_noop_when_dispatch_already_claimed(): void
        {
            $store = new class extends FakeMetadataStore {
                public function claimPasswordDispatch(int $serviceId, string $serverUuid): ?string
                {
                    return null; // idempotency guard hit
                }
            };

            PasswordMailer::queue(['serviceid' => 77], $store, 'uuid-77', 'Pass!');

            $this->assertCount(0, $store->queued);
            $this->assertSame('', $store->meta['midgard_pending_password'] ?? '');
        }

        public function test_async_send_uses_sealed_password_and_finalizes(): void
        {
            $store = new FakeMetadataStore();
            $store->setMeta([
                'midgard_pending_password' => 'plain:' . base64_encode('SealedSecret1!'),
                'midgard_welcome_template' => 'Midgard Provisioning Credentials',
            ]);

            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'success'],
            ];

            PasswordMailer::sendOneTime(
                ['serviceid' => 77, 'userid' => 88],
                $store,
                'uuid-77',
                null,
                'dispatch-hash'
            );

            $this->assertCount(1, PasswordMailerLocalApiSpy::$calls);
            $this->assertSame('SealedSecret1!', $this->extractVarFromCall(0, 'midgard_server_password'));
            $this->assertCount(1, $store->finalized);
            $this->assertCount(0, $store->released);
            // Sealed password must be wiped after successful delivery.
            $this->assertSame('', $store->meta['midgard_pending_password'] ?? '');
        }

        public function test_async_send_failure_keeps_dispatch_for_retry(): void
        {
            $store = new FakeMetadataStore();
            $store->setMeta([
                'midgard_pending_password' => 'plain:' . base64_encode('RetryPass1!'),
            ]);
            PasswordMailerLocalApiSpy::$responses = [
                ['result' => 'error', 'message' => 'SMTP timeout'],
                ['result' => 'error', 'message' => 'SMTP timeout'],
            ];

            try {
                PasswordMailer::sendOneTime(
                    ['serviceid' => 77, 'userid' => 88],
                    $store,
                    'uuid-77',
                    null,
                    'dispatch-hash'
                );
                $this->fail('Expected RuntimeException');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Failed to send credentials email', $e->getMessage());
            }

            // The worker only BORROWS the queued row: on failure it must stay
            // queued (released stays empty) so the next cron run retries.
            $this->assertCount(0, $store->finalized);
            $this->assertCount(0, $store->released);
            $this->assertSame('RetryPass1!', base64_decode(
                substr((string) ($store->meta['midgard_pending_password'] ?? ''), 6),
                true
            ));
        }

        public function test_async_send_without_sealed_password_fails_loudly(): void
        {
            $store = new FakeMetadataStore(); // no midgard_pending_password

            // Contract change (prod incident 2026-09-14): the cron worker must
            // SEE the failure — a silent return here used to burn all 5
            // attempts with last_error never recorded.
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Sealed credentials missing');

            PasswordMailer::sendOneTime(
                ['serviceid' => 77, 'userid' => 88],
                $store,
                'uuid-77',
                null,
                'dispatch-hash'
            );
        }

        public function test_queue_then_async_send_roundtrip_delivers_same_password(): void
        {
            $store = new FakeMetadataStore();

            PasswordMailer::queue(['serviceid' => 91, 'userid' => 92], $store, 'uuid-91', 'RoundTrip9!');

            PasswordMailer::sendOneTime(
                ['serviceid' => 91, 'userid' => 92],
                $store,
                'uuid-91',
                null,
                $store->queued[0]
            );

            $this->assertCount(1, PasswordMailerLocalApiSpy::$calls);
            $this->assertSame('RoundTrip9!', $this->extractVarFromCall(0, 'midgard_server_password'));
            $this->assertSame('RoundTrip9!', $this->extractVarFromCall(0, 'service_password'));
            $this->assertSame('RoundTrip9!', $this->extractVarFromCall(0, 'server_password'));
            $this->assertCount(1, $store->finalized);
        }
    }
}
