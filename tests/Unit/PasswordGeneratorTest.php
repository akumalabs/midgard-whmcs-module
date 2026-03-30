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

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9!@#\$%\^&\*\(\)_\+\-=]{16}$/', $password);
    }
}
