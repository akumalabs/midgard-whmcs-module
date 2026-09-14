<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordMailer
{
    /**
     * Queue the one-time credentials email for async delivery by the
     * WHMCS cron worker (see hooks.php AfterCronJob). The password is
     * sealed into the service metadata immediately, so the CreateAccount
     * request never blocks on SMTP — slow mail servers can no longer
     * 504 provisioning, and a failed send can no longer report failure
     * AFTER the server was successfully deployed.
     *
     * @param array<string, mixed> $params
     */
    public static function queue(array $params, MetadataStore $store, string $serverUuid, string $password): void
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId <= 0 || trim($serverUuid) === '' || trim($password) === '') {
            return;
        }

        $dispatchHash = $store->claimPasswordDispatch($serviceId, $serverUuid);
        if ($dispatchHash === null) {
            // Already claimed (idempotency guard) — nothing to do.
            return;
        }

        try {
            $templateName = Config::option($params, 'welcome_email_template', 'Midgard Provisioning Credentials');

            // Targeted patch, NOT a full-meta upsert: a concurrent
            // SyncService::syncFromPanel() built from a pre-seal get() would
            // otherwise wipe the just-sealed blob (this store overwrites the
            // whole row on upsert).
            $patch = ['midgard_pending_password' => self::seal($password)];
            $currentTemplate = trim((string) ($store->get($serviceId)['midgard_welcome_template'] ?? ''));
            if ($currentTemplate !== $templateName) {
                $patch['midgard_welcome_template'] = $templateName;
            }
            $store->patchMeta($serviceId, $patch);

            $store->queuePasswordDispatch($dispatchHash);

            // DiagnosticLogger is defined in DiagnosticSanitizer.php, which is
            // only guaranteed-loaded in the full module bootstrap (WHMCS).
            if (class_exists(\MidgardWhmcs\DiagnosticLogger::class)) {
                \MidgardWhmcs\DiagnosticLogger::log('passwordEmailQueued', [
                    'serviceid' => $serviceId,
                    'dispatch' => substr($dispatchHash, 0, 12),
                ], ['status' => 'queued_for_cron']);
            }
        } catch (\Throwable $e) {
            $store->releasePasswordDispatch($dispatchHash);
            throw $e;
        }
    }

    /**
     * Send the one-time credentials email, injecting:
     *   - Midgard-specific variables (midgard_*, server_password, server_primary_ipv4/v6)
     *   - Standard WHMCS merge field aliases (service_password, service_dedicated_ip)
     *     so that stock WHMCS email templates also render credentials correctly.
     *
     * Two call shapes:
     *   - Sync (legacy/immediate): pass $password — a fresh dispatch is claimed
     *     and released again when sending fails.
     *   - Async (cron worker): pass only $dispatchHash — the sealed password is
     *     loaded from metadata and the dispatch row is kept on failure so the
     *     next cron run can retry.
     *
     * @param array<string, mixed> $params
     */
    public static function sendOneTime(
        array $params,
        MetadataStore $store,
        string $serverUuid,
        ?string $password = null,
        ?string $dispatchHash = null
    ): void {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId <= 0 || trim($serverUuid) === '') {
            // Async path: the dispatch row guarantees a server_uuid existed at
            // queue time — an empty one here means the module tables are
            // corrupt. Fail loudly so the cron worker records last_error
            // instead of silently burning the attempt counter.
            if ($dispatchHash !== null) {
                throw new \RuntimeException("Dispatch {$dispatchHash} has no server_uuid; cannot resolve service context.");
            }

            return;
        }

        $ownsDispatch = $dispatchHash === null;
        if ($ownsDispatch) {
            if (trim((string) $password) === '') {
                return;
            }
            $dispatchHash = $store->claimPasswordDispatch($serviceId, $serverUuid);
            if ($dispatchHash === null) {
                return;
            }
        }

        try {
            if ($password === null) {
                $meta = $store->get($serviceId);
                $password = self::unseal((string) ($meta['midgard_pending_password'] ?? ''));
                if ($password === null || trim($password) === '') {
                    // Nothing sealed (queue() failed to persist, or the blob is
                    // corrupt). Throwing records last_error on the dispatch row
                    // and keeps it queued for a manual re-arm — a silent return
                    // here used to burn all 5 attempts with no trace (prod
                    // incident 2026-09-14: upsert() dropped the sealed blob
                    // because the column was missing from the schema).
                    throw new \RuntimeException(
                        "Sealed credentials missing for service {$serviceId} (dispatch {$dispatchHash}); email not sent."
                    );
                }
            }

            if (! function_exists('localAPI') && ! function_exists(__NAMESPACE__ . '\\localAPI')) {
                throw new \RuntimeException('WHMCS localAPI function is unavailable.');
            }

            // Resolve the template: from params when present (sync path), else
            // the value persisted at queue time (cron params carry no
            // configoptions), else the module default.
            $templateName = Config::option($params, 'welcome_email_template', '');
            if ($templateName === '') {
                $templateName = trim((string) ($store->get($serviceId)['midgard_welcome_template'] ?? ''));
            }
            if ($templateName === '') {
                $templateName = 'Midgard Provisioning Credentials';
            }

            $clientId = (int) ($params['userid'] ?? 0);
            if ($serviceId <= 0 && $clientId <= 0) {
                throw new \RuntimeException('Unable to send credentials email: missing service/client ID.');
            }

            // Persist the resolved template name so the EmailPreSend hook can
            // compare against this authoritative value (resolved via Config::option()
            // which reads WHMCS's own $params['configoptions'] friendly-key array)
            // instead of re-deriving it from a guessed configoptionN column index.
            if (trim((string) ($store->get($serviceId)['midgard_welcome_template'] ?? '')) !== $templateName) {
                $store->upsert($serviceId, array_merge($store->get($serviceId), [
                    'midgard_welcome_template' => $templateName,
                ]));
            }

            // Pull the latest synced metadata for canonical primary IPs.
            $meta = $store->get($serviceId);
            $primaryIpv4 = trim((string) ($meta['midgard_primary_ipv4'] ?? ''));
            $primaryIpv6 = trim((string) ($meta['midgard_primary_ipv6'] ?? ''));

            // The dedicated IP for standard WHMCS templates defaults to the
            // canonical primary (IPv4 preferred, IPv6 fallback).
            $dedicatedIp = $primaryIpv4 !== '' ? $primaryIpv4 : $primaryIpv6;

            $customVars = base64_encode(serialize([
                // Midgard-native keys (existing templates)
                'midgard_server_password' => $password,
                'midgard_primary_ipv4' => $primaryIpv4,
                'midgard_primary_ipv6' => $primaryIpv6,

                // Standard WHMCS merge field aliases so stock templates
                // (e.g., "Hosting Account Welcome Email") render correctly.
                'service_password' => $password,
                'service_dedicated_ip' => $dedicatedIp,

                // Legacy alias kept for backward compatibility.
                'server_password' => $password,
            ]));

            $attempts = [];
            if ($serviceId > 0) {
                $attempts[] = ['type' => 'service', 'id' => $serviceId];
            }
            if ($clientId > 0 && $clientId !== $serviceId) {
                $attempts[] = ['type' => 'client', 'id' => $clientId];
            }

            $lastFailure = null;
            foreach ($attempts as $attempt) {
                $result = localAPI('SendEmail', [
                    'messagename' => $templateName,
                    'id' => $attempt['id'],
                    'customvars' => $customVars,
                ]);

                if (($result['result'] ?? 'error') === 'success') {
                    $store->finalizePasswordDispatch($serviceId, $dispatchHash);
                    self::clearSealedPassword($store, $serviceId);
                    return;
                }

                $message = (string) ($result['message'] ?? 'Failed to send email.');
                $lastFailure = [
                    'type' => $attempt['type'],
                    'id' => (int) $attempt['id'],
                    'message' => $message,
                    'result' => $result,
                ];

                if (function_exists('logModuleCall')) {
                    DiagnosticLogger::log(
                        'sendOneTimePasswordEmail.attemptFailed',
                        [
                            'serviceid' => $serviceId,
                            'template' => $templateName,
                            'attempt_type' => $attempt['type'],
                            'attempt_id' => (int) $attempt['id'],
                        ],
                        [
                            'result' => (string) ($result['result'] ?? 'error'),
                            'message' => (string) ($result['message'] ?? 'Email dispatch failed.'),
                        ]
                    );
                }
            }

            $lastType = (string) ($lastFailure['type'] ?? 'unknown');
            $lastId = (int) ($lastFailure['id'] ?? 0);
            $lastMessage = (string) ($lastFailure['message'] ?? 'Failed to send email.');
            throw new \RuntimeException(
                "Failed to send credentials email after {$lastType} attempt (id={$lastId}): {$lastMessage}"
            );
        } catch (\Throwable $e) {
            // Only release when we own the dispatch (sync path). The async
            // worker borrows an existing queued row and must keep it so the
            // next cron run can retry.
            if ($ownsDispatch) {
                $store->releasePasswordDispatch($dispatchHash);
            }
            throw $e;
        }
    }

    /**
     * Seal a password into metadata storage. Prefers WHMCS's own
     * encryption (same trust domain as the service password fields);
     * falls back to reversible base64 when encrypt() is unavailable.
     */
    private static function seal(string $password): string
    {
        if (function_exists('encrypt')) {
            $encrypted = @\encrypt($password);
            if (is_string($encrypted) && $encrypted !== '') {
                return 'enc:' . base64_encode($encrypted);
            }
        }

        return 'plain:' . base64_encode($password);
    }

    private static function unseal(string $blob): ?string
    {
        if ($blob === '') {
            return null;
        }

        if (str_starts_with($blob, 'enc:')) {
            if (! function_exists('decrypt')) {
                return null;
            }
            $raw = base64_decode(substr($blob, 4), true);
            if ($raw === false || $raw === '') {
                return null;
            }
            $decrypted = @\decrypt($raw);

            return is_string($decrypted) && $decrypted !== '' ? $decrypted : null;
        }

        if (str_starts_with($blob, 'plain:')) {
            $raw = base64_decode(substr($blob, 6), true);

            return is_string($raw) && $raw !== '' ? $raw : null;
        }

        return null;
    }

    private static function clearSealedPassword(MetadataStore $store, int $serviceId): void
    {
        // Targeted clear — see MetadataStore::patchMeta() for why a full
        // upsert here would race with concurrent sync writes.
        if (trim((string) ($store->get($serviceId)['midgard_pending_password'] ?? '')) !== '') {
            $store->patchMeta($serviceId, ['midgard_pending_password' => '']);
        }
    }
}
