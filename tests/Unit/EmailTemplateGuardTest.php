<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\EmailTemplateGuard;
use PHPUnit\Framework\TestCase;

final class EmailTemplateGuardTest extends TestCase
{
    public function test_evaluate_credentials_template_send_allows_when_password_merge_var_present(): void
    {
        $result = EmailTemplateGuard::evaluateCredentialsTemplateSend(
            [
                'messagename' => 'Midgard Provisioning Credentials',
                'mergefields' => [
                    'midgard_server_password' => 'SecretPass123!',
                ],
            ],
            'Midgard Provisioning Credentials'
        );

        $this->assertFalse($result['block']);
        $this->assertTrue($result['matches_template']);
        $this->assertTrue($result['password_present']);
        $this->assertSame('password_present', $result['reason']);
    }

    public function test_evaluate_credentials_template_send_blocks_when_password_merge_var_missing(): void
    {
        $result = EmailTemplateGuard::evaluateCredentialsTemplateSend(
            [
                'messagename' => 'Midgard Provisioning Credentials',
                'mergefields' => [
                    'service_id' => 123,
                ],
            ],
            'Midgard Provisioning Credentials'
        );

        $this->assertTrue($result['block']);
        $this->assertTrue($result['matches_template']);
        $this->assertFalse($result['password_present']);
        $this->assertSame('missing_midgard_server_password', $result['reason']);
    }
}
