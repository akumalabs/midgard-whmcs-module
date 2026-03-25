<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class ProvisionStateMapper
{
    /**
     * @param array<string, mixed> $progressPayload
     * @return array{state: string, error: string}
     */
    public static function fromInstallProgress(array $progressPayload): array
    {
        $status = strtolower((string) ($progressPayload['status'] ?? ''));
        $step = strtolower((string) ($progressPayload['step'] ?? ''));

        if ($status === 'failed' || $step === 'failed') {
            $error = trim((string) ($progressPayload['error'] ?? 'Installation failed.'));
            if ($error === '') {
                $error = 'Installation failed.';
            }

            return [
                'state' => 'failed',
                'error' => $error,
            ];
        }

        if ($status === 'completed' || $status === 'ready' || $step === 'completed') {
            return [
                'state' => 'ready',
                'error' => '',
            ];
        }

        return [
            'state' => 'installing',
            'error' => '',
        ];
    }

    /**
     * @param array<string, mixed> $serverPayload
     * @return array{state: string, error: string}
     */
    public static function fromServerStatus(array $serverPayload): array
    {
        $status = strtolower((string) ($serverPayload['status'] ?? ''));

        if (in_array($status, ['failed', 'install_failed'], true)) {
            return [
                'state' => 'failed',
                'error' => trim((string) ($serverPayload['install_error_detail'] ?? 'Installation failed.')),
            ];
        }

        if (in_array($status, ['running', 'stopped'], true)) {
            return [
                'state' => 'ready',
                'error' => '',
            ];
        }

        return [
            'state' => 'installing',
            'error' => '',
        ];
    }
}
