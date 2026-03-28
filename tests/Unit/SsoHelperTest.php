<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\SsoHelper;
use PHPUnit\Framework\TestCase;

final class SsoHelperTest extends TestCase
{
    public function test_build_sso_url_uses_user_id_first_and_falls_back_to_email(): void
    {
        $attempts = [];

        $url = SsoHelper::buildSsoUrlWithIssuer(
            static function (array $payload) use (&$attempts): array {
                $attempts[] = $payload;
                if (isset($payload['user_id'])) {
                    throw new \RuntimeException('user-id lookup failed');
                }

                return ['data' => ['ticket' => 'fallback-ticket']];
            },
            [
                'serverhostname' => 'panel.example.test',
                'clientsdetails' => ['email' => 'client@example.test'],
            ],
            [
                'midgard_server_uuid' => 'server-uuid-123',
                'midgard_user_id' => '42',
            ]
        );

        $this->assertSame('https://panel.example.test/auth/login?sso_ticket=fallback-ticket', $url);
        $this->assertCount(2, $attempts);
        $this->assertSame(
            ['user_id' => 42, 'server_uuid' => 'server-uuid-123'],
            $attempts[0]
        );
        $this->assertSame(
            ['email' => 'client@example.test', 'server_uuid' => 'server-uuid-123'],
            $attempts[1]
        );
    }

    public function test_build_sso_url_succeeds_on_user_id_without_email_fallback(): void
    {
        $attempts = [];

        $url = SsoHelper::buildSsoUrlWithIssuer(
            static function (array $payload) use (&$attempts): array {
                $attempts[] = $payload;
                return ['data' => ['ticket' => 'user-id-ticket']];
            },
            [
                'serverhostname' => 'panel.example.test',
                'clientsdetails' => ['email' => ''],
            ],
            [
                'midgard_server_uuid' => 'server-uuid-123',
                'midgard_user_id' => '9',
            ]
        );

        $this->assertSame('https://panel.example.test/auth/login?sso_ticket=user-id-ticket', $url);
        $this->assertCount(1, $attempts);
        $this->assertSame(
            ['user_id' => 9, 'server_uuid' => 'server-uuid-123'],
            $attempts[0]
        );
    }

    public function test_build_sso_url_returns_null_when_all_attempts_fail(): void
    {
        $attempts = [];

        $url = SsoHelper::buildSsoUrlWithIssuer(
            static function (array $payload) use (&$attempts): array {
                $attempts[] = $payload;
                throw new \RuntimeException('ticket failure');
            },
            [
                'serverhostname' => 'panel.example.test',
                'clientsdetails' => ['email' => 'client@example.test'],
            ],
            [
                'midgard_server_uuid' => 'server-uuid-123',
                'midgard_user_id' => '42',
            ]
        );

        $this->assertNull($url);
        $this->assertCount(2, $attempts);
    }
}
