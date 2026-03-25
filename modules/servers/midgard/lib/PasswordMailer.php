<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class PasswordMailer
{
    /**
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
            if (! function_exists('localAPI')) {
                throw new \RuntimeException('WHMCS localAPI function is unavailable.');
            }

            $templateName = Config::option($params, 'welcome_email_template', 'Midgard Provisioning Credentials');
            $clientId = (int) ($params['userid'] ?? 0);
            if ($clientId <= 0) {
                throw new \RuntimeException('Unable to send credentials email: missing client ID.');
            }

            $result = localAPI('SendEmail', [
                'messagename' => $templateName,
                'id' => $clientId,
                'customvars' => base64_encode(serialize([
                    'midgard_server_password' => $password,
                ])),
            ]);

            if (($result['result'] ?? 'error') !== 'success') {
                $message = (string) ($result['message'] ?? 'Failed to send email.');
                throw new \RuntimeException($message);
            }

            $store->finalizePasswordDispatch($serviceId, $dispatchHash);
        } catch (\Throwable $e) {
            $store->releasePasswordDispatch($dispatchHash);
            throw $e;
        }
    }
}
