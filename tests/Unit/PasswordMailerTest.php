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

    use MidgardWhmcs\PasswordDispatchStore;
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

    final class FakePasswordDispatchStore implements PasswordDispatchStore
    {
        /** @var array<int, array<string, mixed>> */
        public array $claims = [];

        /** @var array<int, array<string, mixed>> */
        public array $finalized = [];

        /** @var array<int, string> */
        public array $released = [];

        public function claimPasswordDispatch(int $serviceId, string $serverUuid): ?string
        {
            $this->claims[] = [
                'service_id' => $serviceId,
                'server_uuid' => $serverUuid,
            ];

            return 'dispatch-hash';
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
            $store = new FakePasswordDispatchStore();
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
            $this->assertSame('SecretPass123!', $this->extractPasswordFromCall(0));
        }

        public function test_send_one_time_falls_back_to_client_id_when_service_attempt_fails(): void
        {
            $store = new FakePasswordDispatchStore();
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
            $this->assertSame('AnotherPass456!', $this->extractPasswordFromCall(1));
        }

        public function test_send_one_time_releases_dispatch_when_all_attempts_fail(): void
        {
            $store = new FakePasswordDispatchStore();
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

        private function extractPasswordFromCall(int $callIndex): string
        {
            $encodedVars = (string) (PasswordMailerLocalApiSpy::$calls[$callIndex]['values']['customvars'] ?? '');
            $decodedVars = @unserialize(base64_decode($encodedVars), ['allowed_classes' => false]);
            if (! is_array($decodedVars)) {
                return '';
            }

            return (string) ($decodedVars['midgard_server_password'] ?? '');
        }
    }
}
