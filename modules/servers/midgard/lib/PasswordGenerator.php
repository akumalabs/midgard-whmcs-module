<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordGenerator
{
    private const LENGTH = 16;
    private const SYMBOLS = '!@#$%^&*()_+-=';
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    private const LOWER = 'abcdefghijkmnopqrstuvwxyz';
    private const DIGITS = '23456789';
    private const ALPHABET = self::UPPER . self::LOWER . self::DIGITS . self::SYMBOLS;

    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $alnumMax = $max - strlen(self::SYMBOLS); // alnum prefix of ALPHABET

        // First character MUST be alphanumeric: the password reaches
        // cloudbase-init inside PVE's generated user-data YAML, and a leading
        // YAML indicator character (e.g. '@', '|', '>', '`') can break the
        // parse (UserDataPlugin ScannerError). Digits still satisfy the
        // panel's ->numbers() rule when combined with the guards below.
        $password = self::ALPHABET[random_int(0, $alnumMax)];

        for ($i = 1; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        // The panel validates passwords with Password::min(8)->letters()
        // ->mixedCase()->numbers()->symbols(). A purely random draw from the
        // alphabet can miss a required class, so guarantee all four —
        // otherwise server creation fails with a 422 the operator never sees.
        // Re-check after each pass: a later replacement can land on a
        // position that carried an earlier guaranteed class. Replacements
        // never touch position 0 (kept alphanumeric, see above).
        $guards = [
            // Guards draw ONLY from the same ambiguity-free classes as
            // ALPHABET above — a plain random_int(0, 9) here used to inject
            // '0'/'1' (and chr() loops could inject 'I'/'O'/'l'), leaking
            // ambiguous characters the alphabet deliberately excludes.
            '/\d/' => fn () => self::DIGITS[random_int(0, strlen(self::DIGITS) - 1)],
            '/[A-Z]/' => fn () => self::UPPER[random_int(0, strlen(self::UPPER) - 1)],
            '/[a-z]/' => fn () => self::LOWER[random_int(0, strlen(self::LOWER) - 1)],
            '/[!@#$%^&*()_+\-=]/' => fn () => self::ALPHABET[random_int(57, $max)],
        ];

        for ($pass = 0; $pass < 50; $pass++) {
            $replaced = false;
            foreach ($guards as $pattern => $replacement) {
                if (! preg_match($pattern, $password)) {
                    $password[random_int(1, self::LENGTH - 1)] = $replacement();
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
