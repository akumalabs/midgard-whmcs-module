<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordMailer
{
    /**
     * @param array<string, mixed> $params
     */
    public static function sendOneTime(array $params, PasswordDispatchStore $store, string $serverUuid, string $password): void
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

            $customVars = base64_encode(serialize([
                'midgard_server_password' => $password,
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
