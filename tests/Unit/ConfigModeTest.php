<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\Config;
use PHPUnit\Framework\TestCase;

/**
 * Reseller-mode parsing of the server Access Hash (f2-c2 contract):
 * prefix "reseller|" selects the reseller API surface; everything else
 * must behave EXACTLY as before (admin mode, token passed through).
 *
 * @covers \MidgardWhmcs\Config
 */
final class ConfigModeTest extends TestCase
{
    public function test_mode_defaults_to_admin_without_prefix(): void
    {
        $params = ['serveraccesshash' => 'plain-admin-token'];

        $this->assertSame(Config::MODE_ADMIN, Config::mode($params));
        $this->assertSame(Config::ADMIN_BASE_PATH, Config::basePath($params));
        $this->assertSame('/api/v1/admin', Config::basePath($params));
    }

    public function test_mode_reseller_with_literal_prefix(): void
    {
        $params = ['serveraccesshash' => 'reseller|abc123'];

        $this->assertSame(Config::MODE_RESELLER, Config::mode($params));
        $this->assertSame(Config::RESELLER_BASE_PATH, Config::basePath($params));
        $this->assertSame('/api/v1/reseller', Config::basePath($params));
    }

    public function test_api_token_strips_reseller_prefix(): void
    {
        $this->assertSame(
            'abc123',
            Config::apiToken(['serveraccesshash' => 'reseller|abc123'])
        );
    }

    public function test_api_token_without_prefix_is_unchanged(): void
    {
        // 100% backward compatibility: no prefix → the legacy trimmed
        // token, byte-for-byte (legacy resolution always trims).
        $this->assertSame(
            'admin-token',
            Config::apiToken(['serveraccesshash' => ' admin-token '])
        );
    }

    public function test_mode_falls_back_to_serverpassword_field(): void
    {
        $params = ['serveraccesshash' => '', 'serverpassword' => 'reseller|tok'];

        $this->assertSame(Config::MODE_RESELLER, Config::mode($params));
        $this->assertSame('tok', Config::apiToken($params));
    }

    public function test_token_containing_reseller_late_in_string_is_admin(): void
    {
        // Only a LEADING literal "reseller|" switches modes.
        $params = ['serveraccesshash' => 'token-reseller|x'];

        $this->assertSame(Config::MODE_ADMIN, Config::mode($params));
        $this->assertSame('token-reseller|x', Config::apiToken($params));
    }

    public function test_empty_token_after_prefix_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::apiToken(['serveraccesshash' => 'reseller|   ']);
    }

    public function test_missing_token_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::apiToken([]);
    }

    public function test_mode_ignores_whitespace_prefix_spacing(): void
    {
        // The raw value is trimmed before prefix detection, so leading
        // whitespace does not hide the mode switch.
        $params = ['serveraccesshash' => "  reseller|tok"];

        $this->assertSame(Config::MODE_RESELLER, Config::mode($params));
        $this->assertSame('tok', Config::apiToken($params));
    }
}
