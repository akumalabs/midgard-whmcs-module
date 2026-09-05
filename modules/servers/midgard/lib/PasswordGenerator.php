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

        // The panel validates passwords with Password::min(8)->letters()
        // ->mixedCase()->numbers()->symbols(). A purely random draw from the
        // alphabet can miss a required class, so guarantee all four —
        // otherwise server creation fails with a 422 the operator never sees.
        // Re-check after each pass: a later replacement can land on a
        // position that carried an earlier guaranteed class.
        $guards = [
            '/\d/' => fn () => (string) random_int(0, 9),
            '/[A-Z]/' => fn () => chr(random_int(65, 90)),
            '/[a-z]/' => fn () => chr(random_int(97, 122)),
            '/[!@#$%^&*()_+\-=]/' => fn () => self::ALPHABET[random_int(57, $max)],
        ];

        for ($pass = 0; $pass < 50; $pass++) {
            $replaced = false;
            foreach ($guards as $pattern => $replacement) {
                if (! preg_match($pattern, $password)) {
                    $password[random_int(0, self::LENGTH - 1)] = $replacement();
                    $replaced = true;
                }
            }
            if (! $replaced) {
                break; // a full pass with every class present — done
            }
        }

        return $password;
    }

    public static function length(): int
    {
        return self::LENGTH;
    }
}
