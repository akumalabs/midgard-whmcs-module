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
     * @param array<string, mixed> $hookVars
     */
    private static function extractPassword(array $hookVars): string
    {
        $fromMergeFields = self::extractNamedValue($hookVars['mergefields'] ?? null, 'midgard_server_password');
        if ($fromMergeFields !== '') {
            return $fromMergeFields;
        }

        $fromCustomVarsArray = self::extractNamedValue($hookVars['customvars'] ?? null, 'midgard_server_password');
        if ($fromCustomVarsArray !== '') {
            return $fromCustomVarsArray;
        }

        $customVarsRaw = $hookVars['customvars'] ?? null;
        if (is_string($customVarsRaw) && trim($customVarsRaw) !== '') {
            $decoded = @base64_decode($customVarsRaw, true);
            if ($decoded !== false) {
                $unserialized = @unserialize($decoded, ['allowed_classes' => false]);
                $decodedValue = self::extractNamedValue($unserialized, 'midgard_server_password');
                if ($decodedValue !== '') {
                    return $decodedValue;
                }
            }
        }

        return '';
    }

    /**
     * @param mixed $source
     */
    private static function extractNamedValue($source, string $key): string
    {
        if (! is_array($source)) {
            return '';
        }

        $target = self::normalizeKey($key);
        foreach ($source as $rowKey => $rowValue) {
            if (self::normalizeKey((string) $rowKey) !== $target) {
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
}
