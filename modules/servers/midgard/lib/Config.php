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
        $value = self::resolveOptionValue($params, $key);
        if ($value === null) {
            $value = $params[$key] ?? $default;
        }

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

    /**
     * @return array{
     *   location_id: int,
     *   os_image_id: int,
     *   valid: bool,
     *   errors: array<string, string>
     * }
     */
    public static function validateCriticalProvisioningIds(array $params): array
    {
        $locationId = self::intOption($params, 'location_id', 0);
        $osImageId = self::intOption($params, 'os_image_id', 0);
        $errors = [];

        if ($locationId <= 0) {
            $errors['location_id'] = 'location_id must be a positive integer in Module Settings.';
        }
        if ($osImageId <= 0) {
            $errors['os_image_id'] = 'os_image_id must be a positive integer in Module Settings.';
        }

        return [
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
            'valid' => $errors === [],
            'errors' => $errors,
        ];
    }

    private static function resolveOptionValue(array $params, string $key): mixed
    {
        $configOptions = is_array($params['configoptions'] ?? null)
            ? $params['configoptions']
            : [];

        if (array_key_exists($key, $configOptions)) {
            return $configOptions[$key];
        }

        $normalizedKey = self::normalizeOptionKey($key);

        foreach ($configOptions as $optionKey => $optionValue) {
            if (self::normalizeOptionKey((string) $optionKey) === $normalizedKey) {
                return $optionValue;
            }
        }

        $flatKey = 'configoption_' . $key;
        if (array_key_exists($flatKey, $params)) {
            return $params[$flatKey];
        }

        foreach ($params as $paramKey => $paramValue) {
            if (! is_string($paramKey) || ! str_starts_with(strtolower($paramKey), 'configoption_')) {
                continue;
            }

            $suffix = substr($paramKey, strlen('configoption_'));
            if (self::normalizeOptionKey($suffix) === $normalizedKey) {
                return $paramValue;
            }
        }

        $configOptionIndex = self::configOptionIndexForKey($key);
        if ($configOptionIndex !== null) {
            $numericKey = 'configoption' . $configOptionIndex;
            if (array_key_exists($numericKey, $params)) {
                return $params[$numericKey];
            }
        }

        return null;
    }

    private static function normalizeOptionKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    private static function configOptionIndexForKey(string $key): ?int
    {
        if (! function_exists('midgard_ConfigOptions')) {
            return null;
        }

        $configOptions = midgard_ConfigOptions();
        if (! is_array($configOptions)) {
            return null;
        }

        $target = self::normalizeOptionKey($key);
        $index = 1;

        foreach (array_keys($configOptions) as $optionKey) {
            if (self::normalizeOptionKey((string) $optionKey) === $target) {
                return $index;
            }
            $index++;
        }

        return null;
    }
}
