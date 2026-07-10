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

        // Guarantee at least one digit — the panel's password validation
        // requires it, and a purely random draw misses digits ~15% of the time
        // with a 16-char alphabet that is only ~11% digits.
        if (! preg_match('/\d/', $password)) {
            $pos = random_int(0, self::LENGTH - 1);
            $password[$pos] = (string) random_int(0, 9);
        }

        return $password;
    }

    public static function length(): int
    {
        return self::LENGTH;
    }
}
