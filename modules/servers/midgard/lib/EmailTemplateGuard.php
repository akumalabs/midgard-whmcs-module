<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class EmailTemplateGuard
{
    /**
     * @param array<string, mixed> $hookVars
     * @return array{block: bool, reason: string, matches_template: bool, password_present: bool}
     */
    public static function evaluateCredentialsTemplateSend(array $hookVars, string $configuredTemplate): array
    {
        $template = trim($configuredTemplate);
        if ($template === '') {
            return [
                'block' => false,
                'reason' => 'template_not_configured',
                'matches_template' => false,
                'password_present' => false,
            ];
        }

        $messageName = trim((string) ($hookVars['messagename'] ?? $hookVars['messageName'] ?? ''));
        $matchesTemplate = self::normalizeKey($messageName) === self::normalizeKey($template);
        if (! $matchesTemplate) {
            return [
                'block' => false,
                'reason' => 'template_mismatch',
                'matches_template' => false,
                'password_present' => false,
            ];
        }

        $password = self::extractPassword($hookVars);
        $passwordPresent = trim($password) !== '';

        return [
            'block' => ! $passwordPresent,
            'reason' => $passwordPresent ? 'password_present' : 'missing_midgard_server_password',
            'matches_template' => true,
            'password_present' => $passwordPresent,
        ];
    }

    /**
     * Accepted password key aliases, in priority order:
     *   1. midgard_server_password (Midgard-native)
     *   2. service_password        (standard WHMCS merge field)
     *   3. server_password         (legacy alias)
     */
    private const PASSWORD_KEYS = [
        'midgard_server_password',
        'service_password',
        'server_password',
    ];

    /**
     * @param array<string, mixed> $hookVars
     */
    private static function extractPassword(array $hookVars): string
    {
        $fromMergeFields = self::extractNamedValue($hookVars['mergefields'] ?? null, self::PASSWORD_KEYS);
        if ($fromMergeFields !== '') {
            return $fromMergeFields;
        }

        $fromCustomVarsArray = self::extractNamedValue($hookVars['customvars'] ?? null, self::PASSWORD_KEYS);
        if ($fromCustomVarsArray !== '') {
            return $fromCustomVarsArray;
        }

        $customVarsRaw = $hookVars['customvars'] ?? null;
        if (is_string($customVarsRaw) && trim($customVarsRaw) !== '') {
            $decoded = @base64_decode($customVarsRaw, true);
            if ($decoded !== false) {
                $unserialized = @unserialize($decoded, ['allowed_classes' => false]);
                $decodedValue = self::extractNamedValue($unserialized, self::PASSWORD_KEYS);
                if ($decodedValue !== '') {
                    return $decodedValue;
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $source
     * @param string[] $keys
     */
    private static function extractNamedValue($source, array $keys): string
    {
        if (! is_array($source)) {
            return '';
        }

        $targets = array_map('self::normalizeKey', $keys);
        foreach ($source as $rowKey => $rowValue) {
            $normalizedKey = self::normalizeKey((string) $rowKey);
            if (! in_array($normalizedKey, $targets, true)) {
                continue;
            }

            return trim((string) $rowValue);
        }

        return '';
    }

    private static function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
    }

    /**
     * Detect WHMCS's built-in "Hosting Account Welcome Email" and its
     * common aliases. These are auto-fired by WHMCS when a service
     * reaches Active status and lack Midgard-specific merge fields.
     */
    public static function isWhmcsDefaultWelcomeTemplate(string $messageName): bool
    {
        $normalized = self::normalizeKey($messageName);

        return in_array($normalized, [
            'hostingaccountwelcomeemail',
            'hostingaccountwelcome',
            'welcomeemail',
        ], true);
    }

    /**
     * Check whether the hook variables contain a Midgard password in
     * any of the recognized key locations (mergefields, customvars,
     * or base64-encoded customvars). Used to detect whether an email
     * about to be sent is a re-send that already has our credentials.
     */
    public static function hasMidgardPasswordInVars(array $hookVars): bool
    {
        return self::extractPassword($hookVars) !== '';
    }
}
