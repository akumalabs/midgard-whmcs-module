<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\ApiClient;
use MidgardWhmcs\Config;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\MidgardApiException;
use MidgardWhmcs\PasswordMailer;
use MidgardWhmcs\SsoHelper;
use MidgardWhmcs\SyncService;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/IdempotencyGuard.php';
require_once __DIR__ . '/lib/MetadataStore.php';
require_once __DIR__ . '/lib/PasswordDispatchStore.php';
require_once __DIR__ . '/lib/PasswordMailer.php';
require_once __DIR__ . '/lib/ProvisionStateMapper.php';
require_once __DIR__ . '/lib/SsoHelper.php';
require_once __DIR__ . '/lib/SyncService.php';

function midgard_MetaData(): array
{
    return [
        'DisplayName' => 'Midgard',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function midgard_ConfigOptions(): array
{
    return [
        'location_id' => [
            'Type' => 'text',
            'Size' => '16',
            'Description' => 'Midgard location ID',
        ],
        'os_image_id' => [
            'Type' => 'text',
            'Size' => '16',
            'Description' => 'Midgard OS image ID',
        ],
        'cpu' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '2',
            'Description' => 'vCPU cores',
        ],
        'memory_gb' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '4',
            'Description' => 'RAM in GB',
        ],
        'disk_gb' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '40',
            'Description' => 'Disk in GB',
        ],
        'bandwidth_tb' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '1',
            'Description' => 'Bandwidth in TB',
        ],
        'backup_limit' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Backup slot limit',
        ],
        'snapshot_limit' => [
            'Type' => 'text',
            'Size' => '8',
            'Default' => '0',
            'Description' => 'Snapshot limit',
        ],
        'default_ipv4' => [
            'Type' => 'yesno',
            'Description' => 'Require IPv4 availability in preflight',
        ],
        'default_ipv6' => [
            'Type' => 'yesno',
            'Description' => 'Require IPv6 availability in preflight',
        ],
        'welcome_email_template' => [
            'Type' => 'text',
            'Size' => '64',
            'Default' => 'Midgard Provisioning Credentials',
            'Description' => 'Email template name for one-time password',
        ],
    ];
}

function midgard_TestConnection(array $params): array
{
    try {
        $client = midgard_client($params);
        $client->testConnection();

        return ['success' => true];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function midgard_CreateAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $stage = 'init';
    $panelBaseUrl = '';
    $locationId = 0;
    $osImageId = 0;

    try {
        $store = midgard_store();
        $client = midgard_client($params);
        $panelBaseUrl = Config::panelBaseUrl($params);

        $criticalIds = Config::validateCriticalProvisioningIds($params);
        $locationId = (int) $criticalIds['location_id'];
        $osImageId = (int) $criticalIds['os_image_id'];
        if (! $criticalIds['valid']) {
            $firstError = array_values($criticalIds['errors'])[0] ?? 'Critical module settings are invalid.';
            midgard_logDiagnostic('createAccount.invalidConfig', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'location_id' => $locationId,
                'os_image_id' => $osImageId,
                'errors' => $criticalIds['errors'],
            ]);

            return 'Provisioning blocked: ' . $firstError;
        }

        $cpu = Config::intOption($params, 'cpu', 1);
        $memoryGb = Config::intOption($params, 'memory_gb', 1);
        $diskGb = Config::intOption($params, 'disk_gb', 10);
        $backupLimit = Config::intOption($params, 'backup_limit', 0);
        $snapshotLimit = Config::intOption($params, 'snapshot_limit', 0);
        $bandwidthTb = Config::intOption($params, 'bandwidth_tb', 1);
        $requireIpv4 = Config::boolOption($params, 'default_ipv4', false);
        $requireIpv6 = Config::boolOption($params, 'default_ipv6', false);
        $memoryBytes = $memoryGb * 1024 * 1024 * 1024;
        $diskBytes = $diskGb * 1024 * 1024 * 1024;

        midgard_logDiagnostic('createAccount.resolvedConfig', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
            'cpu' => $cpu,
            'memory_gb' => $memoryGb,
            'disk_gb' => $diskGb,
            'bandwidth_tb' => $bandwidthTb,
            'backup_limit' => $backupLimit,
            'snapshot_limit' => $snapshotLimit,
            'default_ipv4' => $requireIpv4,
            'default_ipv6' => $requireIpv6,
        ]);

        $stage = 'location_check';
        try {
            $locationResponse = $client->getLocation($locationId);
            $locationData = $locationResponse['data'] ?? null;
            $resolvedLocationId = is_array($locationData)
                ? (int) ($locationData['id'] ?? 0)
                : 0;

            if ($resolvedLocationId !== $locationId) {
                midgard_logDiagnostic('createAccount.locationMismatch', [
                    'serviceid' => $serviceId,
                    'panel_base_url' => $panelBaseUrl,
                    'location_id' => $locationId,
                ], $locationResponse);

                return "Provisioning blocked: location_id {$locationId} was not found on connected Midgard panel.";
            }
        } catch (MidgardApiException $e) {
            $message = strtolower($e->getMessage());
            if (
                in_array($e->statusCode(), [404, 422], true)
                || str_contains($message, 'location')
            ) {
                midgard_logDiagnostic('createAccount.locationLookupFailed', [
                    'serviceid' => $serviceId,
                    'panel_base_url' => $panelBaseUrl,
                    'location_id' => $locationId,
                    'status_code' => $e->statusCode(),
                ], [
                    'message' => $e->getMessage(),
                    'payload' => $e->payload(),
                ]);

                return "Provisioning blocked: location_id {$locationId} was not found on connected Midgard panel.";
            }

            midgard_logDiagnostic('createAccount.locationLookupError', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'location_id' => $locationId,
                'status_code' => $e->statusCode(),
            ], [
                'message' => $e->getMessage(),
                'payload' => $e->payload(),
            ]);

            return 'Provisioning blocked: unable to verify selected location_id against connected Midgard panel.';
        }

        $meta = $store->get($serviceId);
        $existingServerId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($existingServerId > 0) {
            $stage = 'reuse_existing_server';
            midgard_logDiagnostic('createAccount.reuseExistingServer', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'existing_server_id' => $existingServerId,
                'existing_server_uuid' => (string) ($meta['midgard_server_uuid'] ?? ''),
                'default_ipv4' => $requireIpv4,
            ]);

            try {
                $existingServerResponse = $client->getServer($existingServerId);
                $existingServerData = $existingServerResponse['data'] ?? [];

                if (! is_array($existingServerData)) {
                    return 'Provisioning blocked: stored Midgard server metadata is invalid.';
                }

                $existingServerUuid = trim((string) ($existingServerData['uuid'] ?? ''));
                if ($existingServerUuid !== '' && $existingServerUuid !== (string) ($meta['midgard_server_uuid'] ?? '')) {
                    $meta['midgard_server_uuid'] = $existingServerUuid;
                }

                $existingServerName = trim((string) ($existingServerData['name'] ?? ''));
                $existingHostname = trim((string) ($existingServerData['hostname'] ?? ''));
                if ($existingServerName !== '' && $existingHostname !== '') {
                    SyncService::syncHostingIdentity($serviceId, $existingServerName, $existingHostname);
                }

                $networkGate = midgard_ensureRequiredNetworking(
                    $client,
                    $existingServerId,
                    $requireIpv4,
                    $serviceId,
                    $panelBaseUrl
                );

                if (! $networkGate['ok']) {
                    $meta['midgard_provision_state'] = 'installing';
                    $meta['midgard_last_error'] = $networkGate['message'];
                    $store->upsert($serviceId, $meta);
                    midgard_setHostingStatus($serviceId, 'Pending');
                    return $networkGate['message'];
                }

                $meta['midgard_last_error'] = '';
                $store->upsert($serviceId, $meta);
                midgard_setHostingStatus($serviceId, 'Active');
                return 'success';
            } catch (MidgardApiException $e) {
                if ($e->statusCode() === 404) {
                    midgard_logDiagnostic('createAccount.reuseExistingServerNotFound', [
                        'serviceid' => $serviceId,
                        'panel_base_url' => $panelBaseUrl,
                        'existing_server_id' => $existingServerId,
                    ], [
                        'message' => $e->getMessage(),
                        'payload' => $e->payload(),
                    ]);

                    $store->clear($serviceId);
                } else {
                    midgard_logDiagnostic('createAccount.reuseExistingServerError', [
                        'serviceid' => $serviceId,
                        'panel_base_url' => $panelBaseUrl,
                        'existing_server_id' => $existingServerId,
                        'status_code' => $e->statusCode(),
                    ], [
                        'message' => $e->getMessage(),
                        'payload' => $e->payload(),
                    ]);

                    return 'Provisioning blocked: unable to verify existing Midgard server metadata.';
                }
            }
        }

        $clientEmail = trim((string) ($params['clientsdetails']['email'] ?? ''));
        if ($clientEmail === '') {
            return 'Client email is required for Midgard provisioning.';
        }

        $user = $client->findUserByEmail($clientEmail);
        if ($user !== null && ! empty($user['is_admin'])) {
            return 'Provisioning blocked: target Midgard user is an admin account.';
        }

        if ($user === null) {
            $generatedUserPassword = midgard_generatePassword();
            $createUserResponse = $client->createUser([
                'name' => midgard_clientName($params),
                'email' => $clientEmail,
                'password' => $generatedUserPassword,
            ]);

            $userData = $createUserResponse['data'] ?? [];
            if (! is_array($userData) || empty($userData['id'])) {
                return 'Midgard user creation failed: missing user ID in response.';
            }
            $user = $userData;
        }

        $preflightPayload = [
            'location_id' => $locationId,
            'cpu' => $cpu,
            'memory' => $memoryBytes,
            'disk' => $diskBytes,
            'default_ipv4' => $requireIpv4,
            'default_ipv6' => $requireIpv6,
        ];

        $stage = 'preflight';
        midgard_logDiagnostic('createAccount.preflightRequest', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'payload' => $preflightPayload,
        ]);

        $preflightResponse = $client->preflight($preflightPayload);
        midgard_logDiagnostic('createAccount.preflightResponse', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'location_id' => $locationId,
            'node_id' => (int) (($preflightResponse['data']['node']['id'] ?? 0)),
        ], $preflightResponse);

        $preflightNodeId = (int) (($preflightResponse['data']['node']['id'] ?? 0));
        if ($preflightNodeId <= 0) {
            return 'Midgard preflight failed: no suitable node was returned.';
        }

        $randomNameResponse = $client->randomName();
        $nameData = $randomNameResponse['data'] ?? [];
        if (! is_array($nameData)) {
            return 'Midgard random-name response was invalid.';
        }

        $serverName = trim((string) ($nameData['name'] ?? ''));
        $hostname = trim((string) ($nameData['hostname'] ?? ''));
        if ($serverName === '' || $hostname === '') {
            return 'Midgard random-name response missing name/hostname.';
        }

        $initialPassword = midgard_generatePassword();
        $createPayload = [
            'user_id' => (int) $user['id'],
            'node_id' => $preflightNodeId,
            'name' => $serverName,
            'hostname' => $hostname,
            'password' => $initialPassword,
            'cpu' => $cpu,
            'memory' => $memoryBytes,
            'disk' => $diskBytes,
            'bandwidth_limit' => $bandwidthTb * 1024 * 1024 * 1024 * 1024,
            'backup_limit' => $backupLimit,
            'snapshot_limit' => $snapshotLimit,
            'os_image_id' => $osImageId,
        ];

        $stage = 'create_server';
        midgard_logDiagnostic('createAccount.createServerRequest', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'node_id' => $preflightNodeId,
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
            'cpu' => $cpu,
            'memory' => $memoryBytes,
            'disk' => $diskBytes,
            'bandwidth_limit' => $createPayload['bandwidth_limit'],
            'backup_limit' => $backupLimit,
            'snapshot_limit' => $snapshotLimit,
        ]);

        $createResponse = $client->createServer($createPayload);
        midgard_logDiagnostic('createAccount.createServerResponse', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
        ], $createResponse);

        $serverData = $createResponse['data'] ?? [];
        if (! is_array($serverData)) {
            return 'Midgard create response was invalid.';
        }

        $midgardServerId = (string) ($serverData['id'] ?? '');
        $midgardServerUuid = (string) ($serverData['uuid'] ?? '');
        if ($midgardServerId === '' || $midgardServerUuid === '') {
            return 'Midgard create response missing server ID/UUID.';
        }

        $meta = $store->get($serviceId);
        $meta['midgard_user_id'] = (string) ($user['id'] ?? '');
        $meta['midgard_server_id'] = $midgardServerId;
        $meta['midgard_server_uuid'] = $midgardServerUuid;
        $meta['midgard_provision_state'] = 'installing';
        $meta['midgard_last_error'] = '';
        $store->upsert($serviceId, $meta);

        SyncService::syncHostingIdentity($serviceId, $serverName, $hostname);

        try {
            PasswordMailer::sendOneTime($params, $store, $midgardServerUuid, $initialPassword);
        } catch (\Throwable $e) {
            logModuleCall('midgard', 'sendOneTimePasswordEmail', ['serviceid' => $serviceId], $e->getMessage(), null, []);
        }

        $stage = 'network_assignment';
        $networkGate = midgard_ensureRequiredNetworking(
            $client,
            (int) $midgardServerId,
            $requireIpv4,
            $serviceId,
            $panelBaseUrl
        );

        if (! $networkGate['ok']) {
            $meta = $store->get($serviceId);
            $meta['midgard_provision_state'] = 'installing';
            $meta['midgard_last_error'] = $networkGate['message'];
            $store->upsert($serviceId, $meta);
            midgard_setHostingStatus($serviceId, 'Pending');
            return $networkGate['message'];
        }

        $meta = $store->get($serviceId);
        $meta['midgard_last_error'] = '';
        $store->upsert($serviceId, $meta);
        midgard_setHostingStatus($serviceId, 'Active');

        return 'success';
    } catch (MidgardApiException $e) {
        $payload = $e->payload();
        midgard_logDiagnostic('createAccount.apiError', [
            'serviceid' => $serviceId,
            'stage' => $stage,
            'panel_base_url' => $panelBaseUrl,
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
            'status_code' => $e->statusCode(),
        ], [
            'message' => $e->getMessage(),
            'payload' => $payload,
        ]);

        if (($payload['error_code'] ?? '') === 'preflight_failed') {
            return midgard_preflightFailureMessage($payload);
        }

        return 'Midgard API error: ' . $e->getMessage();
    } catch (\Throwable $e) {
        midgard_logDiagnostic('createAccount.unexpectedError', [
            'serviceid' => $serviceId,
            'stage' => $stage,
            'panel_base_url' => $panelBaseUrl,
            'location_id' => $locationId,
            'os_image_id' => $osImageId,
        ], [
            'message' => $e->getMessage(),
        ]);

        return 'Provisioning failed: ' . $e->getMessage();
    }
}

function midgard_SuspendAccount(array $params)
{
    try {
        $meta = midgard_store()->get((int) ($params['serviceid'] ?? 0));
        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return 'Midgard server ID is not stored for this service.';
        }

        midgard_client($params)->suspendServer($serverId);
        return 'success';
    } catch (\Throwable $e) {
        return 'Suspend failed: ' . $e->getMessage();
    }
}

function midgard_UnsuspendAccount(array $params)
{
    try {
        $meta = midgard_store()->get((int) ($params['serviceid'] ?? 0));
        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return 'Midgard server ID is not stored for this service.';
        }

        midgard_client($params)->unsuspendServer($serverId);
        return 'success';
    } catch (\Throwable $e) {
        return 'Unsuspend failed: ' . $e->getMessage();
    }
}

function midgard_TerminateAccount(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);

    try {
        $store = midgard_store();
        $meta = $store->get($serviceId);
        $serverId = (int) ($meta['midgard_server_id'] ?? 0);

        if ($serverId > 0) {
            midgard_client($params)->terminateServer($serverId);
        }

        $store->clear($serviceId);
        return 'success';
    } catch (\Throwable $e) {
        return 'Terminate failed: ' . $e->getMessage();
    }
}

function midgard_ChangePackage(array $params)
{
    try {
        $meta = midgard_store()->get((int) ($params['serviceid'] ?? 0));
        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return 'Midgard server ID is not stored for this service.';
        }

        $payload = [
            'cpu' => Config::intOption($params, 'cpu', 1),
            'memory' => Config::intOption($params, 'memory_gb', 1) * 1024 * 1024 * 1024,
            'disk' => Config::intOption($params, 'disk_gb', 10) * 1024 * 1024 * 1024,
            'bandwidth_limit' => Config::intOption($params, 'bandwidth_tb', 1) * 1024 * 1024 * 1024 * 1024,
            'backup_limit' => Config::intOption($params, 'backup_limit', 0),
            'snapshot_limit' => Config::intOption($params, 'snapshot_limit', 0),
        ];

        midgard_client($params)->updateServerResources($serverId, $payload);
        return 'success';
    } catch (\Throwable $e) {
        return 'Change package failed: ' . $e->getMessage();
    }
}

function midgard_ClientArea(array $params): array
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $store = midgard_store();
    $meta = $store->get($serviceId);
    $requireIpv4 = Config::boolOption($params, 'default_ipv4', false);

    try {
        $meta = SyncService::syncFromPanel($params, $store);
    } catch (\Throwable $e) {
        logModuleCall('midgard', 'clientAreaSync', ['serviceid' => $serviceId], $e->getMessage(), null, []);
    }

    $state = strtolower((string) ($meta['midgard_provision_state'] ?? 'installing'));
    $stateLabel = match ($state) {
        'ready' => 'Ready',
        'failed' => 'Failed',
        default => 'Installing',
    };

    $stateClass = match ($state) {
        'ready' => 'midgard-state-ready',
        'failed' => 'midgard-state-failed',
        default => 'midgard-state-installing',
    };

    $ssoUrl = null;
    try {
        if (($meta['midgard_server_uuid'] ?? '') !== '') {
            $ssoUrl = SsoHelper::buildSsoUrl(midgard_client($params), $params, $meta);
        }
    } catch (\Throwable $e) {
        logModuleCall('midgard', 'buildSsoUrl', ['serviceid' => $serviceId], $e->getMessage(), null, []);
    }

    $addresses = [];
    if (is_array($meta['midgard_addresses'] ?? null)) {
        $addresses = $meta['midgard_addresses'];
    }

    $primaryIpv4 = trim((string) ($meta['midgard_primary_ipv4'] ?? ''));
    $primaryIpv6 = trim((string) ($meta['midgard_primary_ipv6'] ?? ''));
    if (($primaryIpv4 === '' || $primaryIpv6 === '') && count($addresses) > 0) {
        $networkSummary = midgard_extractNetworkSummary(['addresses' => $addresses]);
        if ($primaryIpv4 === '') {
            $primaryIpv4 = $networkSummary['primary_ipv4'];
        }
        if ($primaryIpv6 === '') {
            $primaryIpv6 = $networkSummary['primary_ipv6'];
        }
    }

    $ipv4Missing = $requireIpv4 && $primaryIpv4 === '';
    $ipv4Warning = $ipv4Missing
        ? 'IPv4 is required for this service but is not currently assigned. Provisioning remains pending until IPv4 is assigned.'
        : '';

    return [
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'vars' => [
            'midgardProvisionState' => $state,
            'midgardProvisionStateLabel' => $stateLabel,
            'midgardProvisionStateClass' => $stateClass,
            'midgardProvisionError' => (string) ($meta['midgard_last_error'] ?? ''),
            'midgardSsoUrl' => $ssoUrl,
            'midgardPrimaryIpv4' => $primaryIpv4,
            'midgardPrimaryIpv6' => $primaryIpv6,
            'midgardAddresses' => $addresses,
            'midgardIpv4Required' => $requireIpv4,
            'midgardIpv4Missing' => $ipv4Missing,
            'midgardIpv4Warning' => $ipv4Warning,
            'midgardSpecs' => [
                'cpu' => Config::intOption($params, 'cpu', 1),
                'memory_gb' => Config::intOption($params, 'memory_gb', 1),
                'disk_gb' => Config::intOption($params, 'disk_gb', 10),
                'bandwidth_tb' => Config::intOption($params, 'bandwidth_tb', 1),
                'backup_limit' => Config::intOption($params, 'backup_limit', 0),
                'snapshot_limit' => Config::intOption($params, 'snapshot_limit', 0),
                'os_image_id' => Config::intOption($params, 'os_image_id', 0),
            ],
        ],
    ];
}

function midgard_AdminServicesTabFields(array $params): array
{
    $meta = midgard_store()->get((int) ($params['serviceid'] ?? 0));

    return [
        'Midgard User ID' => htmlspecialchars((string) ($meta['midgard_user_id'] ?? '')),
        'Midgard Server ID' => htmlspecialchars((string) ($meta['midgard_server_id'] ?? '')),
        'Midgard Server UUID' => htmlspecialchars((string) ($meta['midgard_server_uuid'] ?? '')),
        'Provision State' => htmlspecialchars((string) ($meta['midgard_provision_state'] ?? '')),
        'Last Error' => htmlspecialchars((string) ($meta['midgard_last_error'] ?? '')),
        'Password Email Sent At' => htmlspecialchars((string) ($meta['midgard_password_email_sent_at'] ?? '')),
    ];
}

function midgard_client(array $params): ApiClient
{
    return new ApiClient(
        Config::panelBaseUrl($params),
        Config::apiToken($params)
    );
}

function midgard_store(): MetadataStore
{
    static $store = null;

    if ($store === null) {
        $store = new MetadataStore();
    }

    return $store;
}

function midgard_generatePassword(int $length = 24): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*()_+-=';
    $max = strlen($alphabet) - 1;

    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }

    return $password;
}

function midgard_clientName(array $params): string
{
    $first = trim((string) ($params['clientsdetails']['firstname'] ?? ''));
    $last = trim((string) ($params['clientsdetails']['lastname'] ?? ''));
    $full = trim($first . ' ' . $last);

    if ($full !== '') {
        return $full;
    }

    $email = trim((string) ($params['clientsdetails']['email'] ?? 'midgard-client'));
    $at = strpos($email, '@');

    return $at === false ? $email : substr($email, 0, $at);
}

function midgard_setHostingStatus(int $serviceId, string $status): void
{
    if ($serviceId <= 0) {
        return;
    }

    Capsule::table('tblhosting')
        ->where('id', $serviceId)
        ->update(['domainstatus' => $status]);
}

/**
 * @param array<string, mixed> $serverData
 * @return array{
 *   addresses: array<int, array{id: int, address: string, type: string, is_primary: bool}>,
 *   primary_ipv4: string,
 *   primary_ipv6: string
 * }
 */
function midgard_extractNetworkSummary(array $serverData): array
{
    $addressesRaw = $serverData['addresses'] ?? [];
    $addresses = [];
    $primaryIpv4 = '';
    $primaryIpv6 = '';

    if (! is_array($addressesRaw)) {
        return [
            'addresses' => [],
            'primary_ipv4' => '',
            'primary_ipv6' => '',
        ];
    }

    foreach ($addressesRaw as $row) {
        if (! is_array($row)) {
            continue;
        }

        $id = (int) ($row['id'] ?? 0);
        $address = trim((string) ($row['address'] ?? ''));
        $type = strtolower(trim((string) ($row['type'] ?? '')));
        $isPrimary = (bool) ($row['is_primary'] ?? false);

        if ($address === '' || ! in_array($type, ['ipv4', 'ipv6'], true)) {
            continue;
        }

        $addresses[] = [
            'id' => $id,
            'address' => $address,
            'type' => $type,
            'is_primary' => $isPrimary,
        ];

        if ($isPrimary && $type === 'ipv4' && $primaryIpv4 === '') {
            $primaryIpv4 = $address;
        }
        if ($isPrimary && $type === 'ipv6' && $primaryIpv6 === '') {
            $primaryIpv6 = $address;
        }
    }

    return [
        'addresses' => $addresses,
        'primary_ipv4' => $primaryIpv4,
        'primary_ipv6' => $primaryIpv6,
    ];
}

/**
 * @return array{
 *   ok: bool,
 *   message: string,
 *   addresses: array<int, array{id: int, address: string, type: string, is_primary: bool}>,
 *   primary_ipv4: string,
 *   primary_ipv6: string
 * }
 */
function midgard_ensureRequiredNetworking(
    ApiClient $client,
    int $serverId,
    bool $requireIpv4,
    int $serviceId,
    string $panelBaseUrl
): array {
    $serverResponse = $client->getServer($serverId);
    $serverData = $serverResponse['data'] ?? [];
    $networkSummary = is_array($serverData)
        ? midgard_extractNetworkSummary($serverData)
        : [
            'addresses' => [],
            'primary_ipv4' => '',
            'primary_ipv6' => '',
        ];

    midgard_logDiagnostic('createAccount.networkSnapshot', [
        'serviceid' => $serviceId,
        'panel_base_url' => $panelBaseUrl,
        'server_id' => $serverId,
        'require_ipv4' => $requireIpv4,
        'primary_ipv4' => $networkSummary['primary_ipv4'],
        'primary_ipv6' => $networkSummary['primary_ipv6'],
        'addresses_count' => count($networkSummary['addresses']),
    ]);

    if (! $requireIpv4 || $networkSummary['primary_ipv4'] !== '') {
        return [
            'ok' => true,
            'message' => '',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    $existingIpv4AddressId = 0;
    foreach ($networkSummary['addresses'] as $address) {
        if (($address['type'] ?? '') === 'ipv4') {
            $existingIpv4AddressId = (int) ($address['id'] ?? 0);
            if ($existingIpv4AddressId > 0) {
                break;
            }
        }
    }

    if ($existingIpv4AddressId > 0) {
        try {
            $setPrimaryResponse = $client->setPrimaryIP($serverId, $existingIpv4AddressId);
            midgard_logDiagnostic('createAccount.autoAssign.setPrimaryExisting', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'server_id' => $serverId,
                'address_id' => $existingIpv4AddressId,
            ], $setPrimaryResponse);

            $refreshedResponse = $client->getServer($serverId);
            $refreshedData = $refreshedResponse['data'] ?? [];
            $refreshedSummary = is_array($refreshedData)
                ? midgard_extractNetworkSummary($refreshedData)
                : $networkSummary;

            if ($refreshedSummary['primary_ipv4'] !== '') {
                return [
                    'ok' => true,
                    'message' => '',
                    'addresses' => $refreshedSummary['addresses'],
                    'primary_ipv4' => $refreshedSummary['primary_ipv4'],
                    'primary_ipv6' => $refreshedSummary['primary_ipv6'],
                ];
            }
        } catch (MidgardApiException $e) {
            midgard_logDiagnostic('createAccount.autoAssign.setPrimaryExistingFailed', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'server_id' => $serverId,
                'address_id' => $existingIpv4AddressId,
                'status_code' => $e->statusCode(),
            ], [
                'message' => $e->getMessage(),
                'payload' => $e->payload(),
            ]);
        }
    }

    try {
        $availableResponse = $client->availableIPs($serverId);
    } catch (MidgardApiException $e) {
        midgard_logDiagnostic('createAccount.autoAssign.availableIpsFailed', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'server_id' => $serverId,
            'status_code' => $e->statusCode(),
        ], [
            'message' => $e->getMessage(),
            'payload' => $e->payload(),
        ]);

        return [
            'ok' => false,
            'message' => 'Provisioning blocked: failed to query available IPv4 addresses for this server.',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    $availableRows = $availableResponse['data'] ?? [];
    if (! is_array($availableRows)) {
        $availableRows = [];
    }

    $availableIpv4 = array_values(array_filter($availableRows, static function ($row): bool {
        return is_array($row) && strtolower(trim((string) ($row['type'] ?? ''))) === 'ipv4';
    }));

    midgard_logDiagnostic('createAccount.autoAssign.availableIps', [
        'serviceid' => $serviceId,
        'panel_base_url' => $panelBaseUrl,
        'server_id' => $serverId,
        'available_total' => count($availableRows),
        'available_ipv4' => count($availableIpv4),
    ]);

    if (count($availableIpv4) === 0) {
        return [
            'ok' => false,
            'message' => 'Provisioning blocked: no IPv4 address available in the selected location pools.',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    $selectedIpv4 = $availableIpv4[0];
    $addressId = (int) ($selectedIpv4['id'] ?? 0);
    if ($addressId <= 0) {
        return [
            'ok' => false,
            'message' => 'Provisioning blocked: selected IPv4 address from pool was invalid.',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    try {
        $assignResponse = $client->assignIP($serverId, $addressId);
        midgard_logDiagnostic('createAccount.autoAssign.assignIp', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'server_id' => $serverId,
            'address_id' => $addressId,
        ], $assignResponse);
    } catch (MidgardApiException $e) {
        midgard_logDiagnostic('createAccount.autoAssign.assignIpFailed', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'server_id' => $serverId,
            'address_id' => $addressId,
            'status_code' => $e->statusCode(),
        ], [
            'message' => $e->getMessage(),
            'payload' => $e->payload(),
        ]);

        return [
            'ok' => false,
            'message' => 'Provisioning blocked: failed to auto-assign an IPv4 address to the server.',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    $assignedAddressId = (int) (($assignResponse['data']['id'] ?? 0));
    if ($assignedAddressId <= 0) {
        $assignedAddressId = $addressId;
    }

    try {
        $setPrimaryResponse = $client->setPrimaryIP($serverId, $assignedAddressId);
        midgard_logDiagnostic('createAccount.autoAssign.setPrimaryIp', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'server_id' => $serverId,
            'address_id' => $assignedAddressId,
        ], $setPrimaryResponse);
    } catch (MidgardApiException $e) {
        midgard_logDiagnostic('createAccount.autoAssign.setPrimaryIpFailed', [
            'serviceid' => $serviceId,
            'panel_base_url' => $panelBaseUrl,
            'server_id' => $serverId,
            'address_id' => $assignedAddressId,
            'status_code' => $e->statusCode(),
        ], [
            'message' => $e->getMessage(),
            'payload' => $e->payload(),
        ]);

        return [
            'ok' => false,
            'message' => 'Provisioning blocked: IPv4 was assigned but could not be marked as primary.',
            'addresses' => $networkSummary['addresses'],
            'primary_ipv4' => $networkSummary['primary_ipv4'],
            'primary_ipv6' => $networkSummary['primary_ipv6'],
        ];
    }

    $refreshedResponse = $client->getServer($serverId);
    $refreshedData = $refreshedResponse['data'] ?? [];
    $refreshedSummary = is_array($refreshedData)
        ? midgard_extractNetworkSummary($refreshedData)
        : $networkSummary;

    if ($refreshedSummary['primary_ipv4'] === '') {
        return [
            'ok' => false,
            'message' => 'Provisioning blocked: IPv4 assignment did not appear on the server after refresh.',
            'addresses' => $refreshedSummary['addresses'],
            'primary_ipv4' => '',
            'primary_ipv6' => $refreshedSummary['primary_ipv6'],
        ];
    }

    return [
        'ok' => true,
        'message' => '',
        'addresses' => $refreshedSummary['addresses'],
        'primary_ipv4' => $refreshedSummary['primary_ipv4'],
        'primary_ipv6' => $refreshedSummary['primary_ipv6'],
    ];
}

/**
 * @param array<string, mixed> $requestData
 * @param mixed $responseData
 */
function midgard_logDiagnostic(string $action, array $requestData, $responseData = null): void
{
    if (! function_exists('logModuleCall')) {
        return;
    }

    logModuleCall('midgard', $action, $requestData, $responseData, null, []);
}

/**
 * @param array<string, mixed> $payload
 */
function midgard_preflightFailureMessage(array $payload): string
{
    $insufficiencies = $payload['insufficiencies'] ?? [];
    if (! is_array($insufficiencies) || count($insufficiencies) === 0) {
        return 'Provisioning preflight failed in Midgard.';
    }

    $first = $insufficiencies[0];
    if (! is_array($first)) {
        return 'Provisioning preflight failed in Midgard.';
    }

    $code = (string) ($first['code'] ?? 'preflight_failed');
    $detail = trim((string) ($first['detail'] ?? ''));

    $message = match ($code) {
        'insufficient_cpu' => 'Provisioning blocked: insufficient CPU on target nodes.',
        'insufficient_memory' => 'Provisioning blocked: insufficient memory on target nodes.',
        'insufficient_disk' => 'Provisioning blocked: insufficient disk on target nodes.',
        'insufficient_ipv4' => 'Provisioning blocked: no IPv4 address available.',
        'insufficient_ipv6' => 'Provisioning blocked: no IPv6 address available.',
        default => 'Provisioning preflight failed in Midgard.',
    };

    if ($detail !== '') {
        $message .= ' ' . $detail;
    }

    return $message;
}
