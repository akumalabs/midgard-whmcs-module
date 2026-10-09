<?php

declare(strict_types=1);

namespace MidgardWhmcs;

/**
 * Helpers for storing/retrieving the webhook HMAC secret in the install KV
 * table with WHMCS's own cipher (encrypt()/decrypt()).
 *
 * Storage format (PasswordMailer seal/unseal convention):
 *   'enc:' . base64_encode(encrypt($secret))
 *
 * Backward compatibility: installs written by older module versions hold the
 * RAW 64-hex secret. On read, a value without the 'enc:' prefix is returned
 * as-is (a legacy raw secret is a valid HMAC key); the NEXT write through
 * storeSecret() upgrades it to the encrypted form.
 */
final class SecretCrypto
{
    /** Prefix marking a WHMCS-encrypted value (PasswordMailer convention). */
    public const ENC_PREFIX = 'enc:';

    private function __construct()
    {
    }

    /**
     * Encrypt a secret for at-rest storage. Throws when WHMCS's cipher is
     * unavailable — a reversible base64 fallback is deliberately NOT offered
     * (same trust domain as the service password fields, Master-locked
     * 2026-10-01 for credentials; the webhook secret gets the same bar).
     *
     * @throws \RuntimeException when encrypt() is missing or fails
     */
    public static function seal(string $secret): string
    {
        if (! function_exists('encrypt')) {
            throw new \RuntimeException(
                'WHMCS encrypt() unavailable — refusing to store the webhook secret unencrypted.'
            );
        }

        $encrypted = @\encrypt($secret);
        if (! is_string($encrypted) || $encrypted === '') {
            throw new \RuntimeException(
                'WHMCS encrypt() failed — refusing to store the webhook secret unencrypted.'
            );
        }

        return self::ENC_PREFIX . base64_encode($encrypted);
    }

    /**
     * Read-side counterpart of seal(): decrypt an 'enc:' blob; pass through
     * anything without the prefix (legacy raw secret) unchanged. Returns ''
     * for undecryptable blobs so the caller's empty-secret check treats them
     * as "not registered" (the next register() run mints and re-seals).
     */
    public static function unseal(string $stored): string
    {
        if ($stored === '') {
            return '';
        }

        if (! str_starts_with($stored, self::ENC_PREFIX)) {
            // Legacy install: the raw secret predates at-rest encryption.
            // It still verifies HMAC signatures; re-sealing happens on the
            // next write (see CallbackRegistrar::register()).
            return $stored;
        }

        if (! function_exists('decrypt')) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || $raw === '') {
            return '';
        }

        $decrypted = @\decrypt($raw);

        return is_string($decrypted) && $decrypted !== '' ? $decrypted : '';
    }
}
