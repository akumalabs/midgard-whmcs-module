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

    try {
        $store = midgard_store();
        $client = midgard_client($params);

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

        $cpu = Config::intOption($params, 'cpu', 1);
        $memoryBytes = Config::intOption($params, 'memory_gb', 1) * 1024 * 1024 * 1024;
        $diskBytes = Config::intOption($params, 'disk_gb', 10) * 1024 * 1024 * 1024;

        $preflightPayload = [
            'location_id' => Config::intOption($params, 'location_id', 0),
            'cpu' => $cpu,
            'memory' => $memoryBytes,
            'disk' => $diskBytes,
            'default_ipv4' => Config::boolOption($params, 'default_ipv4', false),
            'default_ipv6' => Config::boolOption($params, 'default_ipv6', false),
        ];

        $preflightResponse = $client->preflight($preflightPayload);
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
            'bandwidth_limit' => Config::intOption($params, 'bandwidth_tb', 1) * 1024 * 1024 * 1024 * 1024,
            'backup_limit' => Config::intOption($params, 'backup_limit', 0),
            'snapshot_limit' => Config::intOption($params, 'snapshot_limit', 0),
            'os_image_id' => Config::intOption($params, 'os_image_id', 0),
        ];

        $createResponse = $client->createServer($createPayload);
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

        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update(['domainstatus' => 'Active']);

        return 'success';
    } catch (MidgardApiException $e) {
        $payload = $e->payload();

        if (($payload['error_code'] ?? '') === 'preflight_failed') {
            return midgard_preflightFailureMessage($payload);
        }

        return 'Midgard API error: ' . $e->getMessage();
    } catch (\Throwable $e) {
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

    return [
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'vars' => [
            'midgardProvisionState' => $state,
            'midgardProvisionStateLabel' => $stateLabel,
            'midgardProvisionStateClass' => $stateClass,
            'midgardProvisionError' => (string) ($meta['midgard_last_error'] ?? ''),
            'midgardSsoUrl' => $ssoUrl,
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
