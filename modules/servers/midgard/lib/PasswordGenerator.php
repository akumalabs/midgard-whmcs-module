<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordGenerator
{
    private const LENGTH = 16;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()_+-=';

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }

    public static function length(): int
    {
        return self::LENGTH;
    }
}
