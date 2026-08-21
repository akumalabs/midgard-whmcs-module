<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class DiagnosticSanitizer
{
    private const SENSITIVE_KEYS = [
        'password', 'secret', 'token', 'api_key', 'apikey', 'accesshash',
        'private_key', 'customvars', 'authorization', 'cookie',
    ];

    public static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 5) {
            return '[TRUNCATED]';
        }

        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $keyString = strtolower((string) $key);
                if (self::isSensitiveKey($keyString)) {
                    $result[$key] = '[REDACTED]';
                    continue;
                }
                $result[$key] = self::sanitize($item, $depth + 1);
            }
            return $result;
        }

        if (is_string($value)) {
            return strlen($value) > 2000 ? substr($value, 0, 2000) . '...[TRUNCATED]' : $value;
        }

        if (is_object($value)) {
            return '[OBJECT]';
        }

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($key === $sensitive || str_contains($key, $sensitive)) {
                return true;
            }
        }
        return false;
    }
}

final class DiagnosticLogger
{
    public static function log(string $action, array $requestData, mixed $responseData = null): void
    {
        if (! function_exists('logModuleCall')) {
            return;
        }

        \logModuleCall(
            'midgard',
            $action,
            DiagnosticSanitizer::sanitize($requestData),
            DiagnosticSanitizer::sanitize($responseData),
            null,
            []
        );
    }
}
