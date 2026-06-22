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
            $errors['location_id'] = 'Location must be selected via Configurable Options. Run "Sync Catalog" on a Midgard service to generate them.';
        }
        if ($osImageId <= 0) {
            $errors['os_image_id'] = 'OS Image must be selected via Configurable Options. Run "Sync Catalog" on a Midgard service to generate them.';
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

        $value = null;
        $aliases = self::aliasesForKey($key);

        // 1. Exact key match in configoptions.
        if (array_key_exists($key, $configOptions)) {
            $value = $configOptions[$key];
        }

        // 2. Normalized / alias match in configoptions.
        if ($value === null) {
            foreach ($configOptions as $optionKey => $optionValue) {
                $normalizedOption = self::normalizeOptionKey((string) $optionKey);
                if (
                    $normalizedOption === self::normalizeOptionKey($key)
                    || in_array($normalizedOption, $aliases, true)
                ) {
                    $value = $optionValue;
                    break;
                }
            }
        }

        // 3. configoption_{key} flat params.
        if ($value === null) {
            $flatKey = 'configoption_' . $key;
            if (array_key_exists($flatKey, $params)) {
                $value = $params[$flatKey];
            }
        }

        // 4. configoption_* flat params with alias matching.
        if ($value === null) {
            foreach ($params as $paramKey => $paramValue) {
                if (! is_string($paramKey) || ! str_starts_with(strtolower($paramKey), 'configoption_')) {
                    continue;
                }

                $suffix = substr($paramKey, strlen('configoption_'));
                $normalizedSuffix = self::normalizeOptionKey($suffix);
                if (
                    $normalizedSuffix === self::normalizeOptionKey($key)
                    || in_array($normalizedSuffix, $aliases, true)
                ) {
                    $value = $paramValue;
                    break;
                }
            }
        }

        if ($value === null) {
            $configOptionIndex = self::configOptionIndexForKey($key);
            if ($configOptionIndex !== null) {
                $numericKey = 'configoption' . $configOptionIndex;
                if (array_key_exists($numericKey, $params)) {
                    $value = $params[$numericKey];
                }
            }
        }

        // WHMCS dropdown and configurable options use "ID|Display Name" format.
        // Extract just the ID portion for numeric fields like location_id / os_image_id.
        return self::extractIdFromLabel($value);
    }

    /**
     * If a value is in "ID|Display Name" format, return just the ID.
     * Otherwise return the value unchanged.
     */
    private static function extractIdFromLabel(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $pipePos = strpos($value, '|');
        if ($pipePos === false) {
            return $value;
        }

        $idPart = trim(substr($value, 0, $pipePos));
        if (ctype_digit($idPart)) {
            return $idPart;
        }

        return $value;
    }

    private static function normalizeOptionKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /**
     * Return normalized aliases for a given internal key.
     *
     * This allows admins to name configurable options with human-readable
     * names like "Locations" or "OS" instead of the exact internal key.
     *
     * @return list<string>
     */
    private static function aliasesForKey(string $key): array
    {
        $map = [
            'location_id' => ['location', 'locations', 'locationid', 'loc'],
            'os_image_id' => ['os', 'osimage', 'osimageid', 'ostemplate', 'image', 'template'],
        ];

        $normalized = self::normalizeOptionKey($key);

        foreach ($map as $mapKey => $aliases) {
            if (self::normalizeOptionKey($mapKey) === $normalized) {
                return array_map(self::normalizeOptionKey(...), $aliases);
            }
        }

        return [];
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
