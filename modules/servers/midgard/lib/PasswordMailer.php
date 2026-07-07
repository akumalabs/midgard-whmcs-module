<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordMailer
{
    /**
     * Send the one-time credentials email, injecting:
     *   - Midgard-specific variables (midgard_*, server_password, server_primary_ipv4/v6)
     *   - Standard WHMCS merge field aliases (service_password, service_dedicated_ip)
     *     so that stock WHMCS email templates also render credentials correctly.
     *
     * @param array<string, mixed> $params
     */
    public static function sendOneTime(array $params, MetadataStore $store, string $serverUuid, string $password): void
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        if ($serviceId <= 0 || trim($serverUuid) === '' || trim($password) === '') {
            return;
        }

        $dispatchHash = $store->claimPasswordDispatch($serviceId, $serverUuid);
        if ($dispatchHash === null) {
            return;
        }

        try {
            if (! function_exists('localAPI') && ! function_exists(__NAMESPACE__ . '\localAPI')) {
                throw new \RuntimeException('WHMCS localAPI function is unavailable.');
            }

            $templateName = Config::option($params, 'welcome_email_template', 'Midgard Provisioning Credentials');
            $clientId = (int) ($params['userid'] ?? 0);
            if ($serviceId <= 0 && $clientId <= 0) {
                throw new \RuntimeException('Unable to send credentials email: missing service/client ID.');
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
                    logModuleCall(
                        'midgard',
                        'sendOneTimePasswordEmail.attemptFailed',
                        [
                            'serviceid' => $serviceId,
                            'template' => $templateName,
                            'attempt_type' => $attempt['type'],
                            'attempt_id' => (int) $attempt['id'],
                        ],
                        $result,
                        null,
                        []
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
            $store->releasePasswordDispatch($dispatchHash);
            throw $e;
        }
    }
}
