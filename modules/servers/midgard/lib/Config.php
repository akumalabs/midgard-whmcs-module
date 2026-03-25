<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class Config
{
    public static function panelBaseUrl(array $params): string
    {
        $raw = trim((string) ($params['serverhostname'] ?? ''));
        if ($raw === '') {
            throw new \RuntimeException('Midgard panel hostname is not configured.');
        }

        if (! str_starts_with($raw, 'http://') && ! str_starts_with($raw, 'https://')) {
            $raw = 'https://' . $raw;
        }

        return rtrim($raw, '/');
    }

    public static function apiToken(array $params): string
    {
        $token = trim((string) ($params['serveraccesshash'] ?? ''));
        if ($token === '') {
            $token = trim((string) ($params['serverpassword'] ?? ''));
        }

        if ($token === '') {
            throw new \RuntimeException('Midgard API token is not configured (Server Access Hash).');
        }

        return $token;
    }

    public static function option(array $params, string $key, string $default = ''): string
    {
        $value = $params['configoptions'][$key]
            ?? $params['configoption_' . $key]
            ?? $params[$key]
            ?? $default;
        return trim((string) $value);
    }

    public static function intOption(array $params, string $key, int $default = 0): int
    {
        $raw = self::option($params, $key, (string) $default);
        if ($raw === '') {
            return $default;
        }

        return (int) $raw;
    }

    public static function boolOption(array $params, string $key, bool $default = false): bool
    {
        $raw = strtolower(self::option($params, $key, $default ? '1' : '0'));
        return in_array($raw, ['1', 'on', 'true', 'yes'], true);
    }
}
