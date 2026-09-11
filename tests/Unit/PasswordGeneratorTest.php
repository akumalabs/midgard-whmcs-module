<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\PasswordGenerator;
use PHPUnit\Framework\TestCase;

final class PasswordGeneratorTest extends TestCase
{
    public function test_generate_returns_exactly_16_characters(): void
    {
        $password = PasswordGenerator::generate();

        $this->assertSame(16, strlen($password));
        $this->assertSame(16, PasswordGenerator::length());
    }

    public function test_generate_uses_expected_character_set(): void
    {
        $password = PasswordGenerator::generate();

        $this->assertSame(16, strlen($password));
        $this->assertMatchesRegularExpression(
            '/^[A-HJ-NP-Za-km-z2-9!@#$%^&*()_+\-=]{16}$/',
            $password
        );
    }

    public function test_generate_never_starts_with_a_symbol(): void
    {
        // A leading YAML indicator character breaks cloudbase-init's
        // user-data parse (ScannerError) — the first character must be
        // alphanumeric, across many random draws.
        for ($i = 0; $i < 500; $i++) {
            $password = PasswordGenerator::generate();
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]/', $password, "draw {$i}: {$password}");
        }
    }

    public function test_generate_still_guarantees_all_complexity_classes(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $password = PasswordGenerator::generate();
            $this->assertMatchesRegularExpression('/\d/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[!@#$%^&*()_+\-=]/', $password);
        }
    }
}
