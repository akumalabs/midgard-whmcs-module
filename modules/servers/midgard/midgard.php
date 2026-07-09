<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\ApiClient;
use MidgardWhmcs\Config;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\MidgardApiException;
use MidgardWhmcs\PasswordMailer;
use MidgardWhmcs\PasswordGenerator;
use MidgardWhmcs\ProvisioningNetworkService;
use MidgardWhmcs\SsoHelper;
use MidgardWhmcs\SyncService;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/ApiClient.php';
require_once __DIR__ . '/lib/CatalogCache.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/IdempotencyGuard.php';
require_once __DIR__ . '/lib/EmailTemplateGuard.php';
require_once __DIR__ . '/lib/PasswordDispatchStore.php';
require_once __DIR__ . '/lib/MetadataStore.php';
require_once __DIR__ . '/lib/PasswordMailer.php';
require_once __DIR__ . '/lib/PasswordGenerator.php';
require_once __DIR__ . '/lib/ProvisionStateMapper.php';
require_once __DIR__ . '/lib/ProvisioningNetworkService.php';
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

        // Auto-refresh the catalog cache so dropdowns are populated.
        try {
            \MidgardWhmcs\CatalogCache::refresh($client);
        } catch (\Throwable $e) {
            // Non-fatal: catalog refresh must not block connection test.
            logModuleCall('midgard', 'testConnection.catalogRefreshFailed', [], $e->getMessage(), null, []);
        }

        return ['success' => true];
    } catch (\Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

function midgard_AdminCustomButtonArray(): array
{
    return [
        'Refresh from Panel' => 'RefreshFromPanel',
    ];
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
            $domainStatus = midgard_resolveHostingStatus($params, $serviceId);
            midgard_logDiagnostic('createAccount.reuseExistingServer', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'existing_server_id' => $existingServerId,
                'existing_server_uuid' => (string) ($meta['midgard_server_uuid'] ?? ''),
                'default_ipv4' => $requireIpv4,
                'domainstatus' => $domainStatus,
            ]);

            if ($domainStatus === 'active') {
                $meta['midgard_last_error'] = '';
                $store->upsert($serviceId, $meta);
                return 'success';
            }

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

                $meta['midgard_server_id'] = (string) $existingServerId;
                $store->upsert($serviceId, $meta);
                $meta = SyncService::syncFromPanel($params, $store);

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
                    $message = 'Provisioning blocked: unable to verify existing Midgard server metadata.';
                    $meta = $store->get($serviceId);
                    $meta['midgard_last_error'] = $message;
                    $store->upsert($serviceId, $meta);
                    midgard_setHostingStatus($serviceId, 'Pending');
                    midgard_logDiagnostic('createAccount.reuseExistingServerError', [
                        'serviceid' => $serviceId,
                        'panel_base_url' => $panelBaseUrl,
                        'existing_server_id' => $existingServerId,
                        'status_code' => $e->statusCode(),
                    ], [
                        'message' => $e->getMessage(),
                        'payload' => $e->payload(),
                    ]);

                    return $message;
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
            $message = 'Midgard preflight failed: no suitable node was returned.';
            $meta = $store->get($serviceId);
            $meta['midgard_last_error'] = $message;
            $store->upsert($serviceId, $meta);
            midgard_setHostingStatus($serviceId, 'Pending');

            return $message;
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
            'default_ipv4' => $requireIpv4,
            'default_ipv6' => $requireIpv6,
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
            'default_ipv4' => $requireIpv4,
            'default_ipv6' => $requireIpv6,
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
        $midgardServerIdInt = (int) $midgardServerId;
        if ($midgardServerIdInt <= 0) {
            return 'Midgard create response returned an invalid server ID.';
        }

        $meta = $store->get($serviceId);
        $meta['midgard_user_id'] = (string) ($user['id'] ?? '');
        $meta['midgard_server_id'] = $midgardServerId;
        $meta['midgard_server_uuid'] = $midgardServerUuid;
        $meta['midgard_provision_state'] = 'installing';
        $meta['midgard_last_error'] = '';
        $store->upsert($serviceId, $meta);

        SyncService::syncHostingIdentity($serviceId, $serverName, $hostname);

        $ipv4EnsureResult = [
            'ensured' => true,
            'attempted' => false,
            'assigned' => false,
            'primary_ipv4' => '',
            'error' => '',
        ];

        if ($requireIpv4) {
            $stage = 'ensure_required_ipv4';
            $ipv4EnsureResult = ProvisioningNetworkService::ensurePrimaryIpv4($client, $midgardServerIdInt);

            // Sync is deferred to the single consolidated call after all network
            // operations to reduce API round-trips.  ensurePrimaryIpv4 already
            // verifies assignment via its own getServer calls, so we only need
            // to check the direct result.
            $ensuredIpv4 = trim((string) ($ipv4EnsureResult['primary_ip'] ?? ''));
            if (! $ipv4EnsureResult['ensured'] && $ensuredIpv4 === '') {
                $message = 'Provisioning blocked: required IPv4 could not be assigned after server creation.';
                $detail = trim((string) ($ipv4EnsureResult['error'] ?? ''));
                if ($detail !== '') {
                    $message .= ' ' . $detail;
                }

                $rollbackError = '';
                try {
                    $client->terminateServer($midgardServerIdInt);
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                    midgard_logDiagnostic('createAccount.rollbackTerminate.failed', [
                        'serviceid' => $serviceId,
                        'panel_base_url' => $panelBaseUrl,
                        'server_id' => $midgardServerIdInt,
                    ], [
                        'message' => $rollbackError,
                    ]);
                }

                $meta = $store->get($serviceId);
                $meta['midgard_server_id'] = '';
                $meta['midgard_server_uuid'] = '';
                $meta['midgard_addresses'] = [];
                $meta['midgard_primary_ipv4'] = '';
                $meta['midgard_primary_ipv6'] = '';
                $meta['midgard_provision_state'] = 'failed';
                $meta['midgard_last_error'] = $rollbackError === ''
                    ? $message
                    : ($message . ' Rollback failed: ' . $rollbackError);
                $store->upsert($serviceId, $meta);
                midgard_setHostingStatus($serviceId, 'Pending');

                midgard_logDiagnostic('createAccount.requiredIpv4AssignmentFailed', [
                    'serviceid' => $serviceId,
                    'panel_base_url' => $panelBaseUrl,
                    'server_id' => $midgardServerIdInt,
                ], [
                    'ensure_result' => $ipv4EnsureResult,
                    'rollback_error' => $rollbackError,
                ]);

                return (string) $meta['midgard_last_error'];
            }
        }

        // Non-blocking IPv6 enforcement: attempt to ensure a primary IPv6 subnet
        // (/64) is assigned. The panel auto-bootstraps a /128 individual from it.
        // Failures are logged but do NOT block provisioning.
        try {
            $ipv6EnsureResult = ProvisioningNetworkService::ensurePrimaryIpv6($client, $midgardServerIdInt);

            if (! $ipv6EnsureResult['ensured'] && ! empty($ipv6EnsureResult['error'])) {
                midgard_logDiagnostic('createAccount.ipv6Enforcement.skipped', [
                    'serviceid' => $serviceId,
                    'panel_base_url' => $panelBaseUrl,
                    'server_id' => $midgardServerIdInt,
                ], [
                    'ensure_result' => $ipv6EnsureResult,
                ]);
            }
        } catch (\Throwable $e) {
            midgard_logDiagnostic('createAccount.ipv6Enforcement.exception', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'server_id' => $midgardServerIdInt,
            ], [
                'message' => $e->getMessage(),
            ]);
        }

        // Canonical normalization: ensure the panel's primary IP follows the
        // IPv4 > IPv6 priority. This runs AFTER all sequential assignments
        // to resolve any race between ensurePrimaryIpv4 and ensurePrimaryIpv6.
        // Non-blocking: failures are logged but do NOT block provisioning.
        try {
            $normalizeResult = ProvisioningNetworkService::normalizePrimaryIp($client, $midgardServerIdInt);

            if (! $normalizeResult['normalized'] && ! empty($normalizeResult['error'])) {
                midgard_logDiagnostic('createAccount.normalizePrimaryIp.failed', [
                    'serviceid' => $serviceId,
                    'panel_base_url' => $panelBaseUrl,
                    'server_id' => $midgardServerIdInt,
                ], [
                    'normalize_result' => $normalizeResult,
                ]);
            }
        } catch (\Throwable $e) {
            midgard_logDiagnostic('createAccount.normalizePrimaryIp.exception', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'server_id' => $midgardServerIdInt,
            ], [
                'message' => $e->getMessage(),
            ]);
        }

        // Single consolidated sync: refresh panel metadata once after ALL
        // network operations complete so all canonical IPs are hydrated
        // before credential dispatch.  Eliminates redundant intermediate
        // syncs that added ~2-3 round-trips to the provisioning flow.
        try {
            $meta = SyncService::syncFromPanel($params, $store);
        } catch (\Throwable $e) {
            midgard_logDiagnostic('createAccount.syncBeforeEmail.exception', [
                'serviceid' => $serviceId,
                'panel_base_url' => $panelBaseUrl,
                'server_id' => $midgardServerIdInt,
            ], [
                'message' => $e->getMessage(),
            ]);
        }

        try {
            PasswordMailer::sendOneTime($params, $store, $midgardServerUuid, $initialPassword);
        } catch (\Throwable $e) {
            logModuleCall('midgard', 'sendOneTimePasswordEmail', ['serviceid' => $serviceId], $e->getMessage(), null, []);
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
            $message = midgard_preflightFailureMessage($payload);
            $meta = midgard_store()->get($serviceId);
            $meta['midgard_last_error'] = $message;
            midgard_store()->upsert($serviceId, $meta);
            midgard_setHostingStatus($serviceId, 'Pending');

            return $message;
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
        SyncService::resetHostingNetwork($serviceId);
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
    logModuleCall('midgard', 'clientArea.entry', [
        'serviceid' => $serviceId,
    ], [
        'status' => (string) ($params['status'] ?? ''),
    ], null, []);

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

    $runtimeStatus = strtolower(trim((string) ($meta['midgard_runtime_status'] ?? 'unknown')));
    $runtimeStatusLabel = match ($runtimeStatus) {
        'running'    => 'Running',
        'stopped'    => 'Stopped',
        'failed'     => 'Failed',
        'error'      => 'Error',
        'installing' => 'Installing',
        'suspended'  => 'Suspended',
        default      => $runtimeStatus !== '' ? ucfirst($runtimeStatus) : 'Unknown',
    };
    $runtimeStatusClass = match ($runtimeStatus) {
        'running'    => 'success',
        'stopped'    => 'danger',
        'failed',
        'error'      => 'danger',
        'installing' => 'warning',
        'suspended'  => 'suspended',
        default      => 'default',
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

    $configSpecs = [
        'cpu' => Config::intOption($params, 'cpu', 1),
        'memory_gb' => Config::intOption($params, 'memory_gb', 1),
        'disk_gb' => Config::intOption($params, 'disk_gb', 10),
        'bandwidth_tb' => Config::intOption($params, 'bandwidth_tb', 1),
        'backup_limit' => Config::intOption($params, 'backup_limit', 0),
        'snapshot_limit' => Config::intOption($params, 'snapshot_limit', 0),
        'os_image_id' => Config::intOption($params, 'os_image_id', 0),
    ];
    $midgardSpecs = SyncService::buildSpecsForClientArea($configSpecs, $meta);

    $assignedIpsArray = [];
    foreach ($addresses as $addressRow) {
        if (! is_array($addressRow)) {
            continue;
        }
        $isPrimary = (bool) ($addressRow['is_primary'] ?? false);
        if ($isPrimary) {
            continue;
        }

        $address = trim((string) ($addressRow['address'] ?? ''));
        if ($address !== '') {
            $assignedIpsArray[] = $address;
        }
    }
    $assignedIpsText = implode("\n", $assignedIpsArray);
    $primaryIp = $primaryIpv4 !== '' ? $primaryIpv4 : $primaryIpv6;
    $serviceHostname = trim((string) ($meta['midgard_server_hostname'] ?? ''));
    if ($serviceHostname === '') {
        $serviceHostname = trim((string) ($params['domain'] ?? ''));
    }

    $serverName = trim((string) ($meta['midgard_server_name'] ?? ''));
    if ($serverName === '') {
        $serverName = trim((string) ($params['username'] ?? ''));
    }
    $templateVariables = [
        'serviceStatus' => (string) ($params['status'] ?? ''),
        'midgardProvisionState' => $state,
        'midgardProvisionStateLabel' => $stateLabel,
        'midgardProvisionStateClass' => $stateClass,
        'midgardRuntimeStatus' => $runtimeStatus,
        'midgardRuntimeStatusLabel' => $runtimeStatusLabel,
        'midgardRuntimeStatusClass' => $runtimeStatusClass,
        'midgardProvisionError' => (string) ($meta['midgard_last_error'] ?? ''),
        'midgardSsoUrl' => $ssoUrl,
        'midgardServerName' => $serverName,
        'midgardServiceHostname' => $serviceHostname,
        'midgardPrimaryIpv4' => $primaryIpv4,
        'midgardPrimaryIpv6' => $primaryIpv6,
        'midgardAddresses' => $addresses,
        'midgardIpv4Required' => $requireIpv4,
        'midgardIpv4Missing' => $ipv4Missing,
        'midgardIpv4Warning' => $ipv4Warning,
        'midgardSpecs' => $midgardSpecs,
        'midgardPrimaryIp' => $primaryIp,
        'midgardAssignedIps' => $assignedIpsText,
        'midgardAssignedIpsArray' => $assignedIpsArray,
        'midgardServerSpecs' => $midgardSpecs,
    ];
    logModuleCall('midgard', 'clientArea.responseKeys', [
        'serviceid' => $serviceId,
    ], [
        'templatefile' => 'clientarea',
        'has_template_variables' => true,
        'has_vars' => true,
    ], null, []);

    return [
        'templatefile' => 'clientarea',
        'requirelogin' => true,
        'templateVariables' => $templateVariables,
        'vars' => $templateVariables,
    ];
}

function midgard_AdminServicesTabFields(array $params): array
{
    $meta = midgard_store()->get((int) ($params['serviceid'] ?? 0));
    $serverId = htmlspecialchars((string) ($meta['midgard_server_id'] ?? ''), ENT_QUOTES, 'UTF-8');
    $serverIdField = <<<HTML
<input type="text" class="form-control input-200 input-inline" name="modulefields[midgard_server_id]" value="{$serverId}" />
<span class="text-info">&nbsp;&nbsp;Use this only for manual rebinds. Save to bind/unbind, then run "Refresh from Panel".</span>
HTML;

    $metaBlock = [
        'midgard_server_uuid' => (string) ($meta['midgard_server_uuid'] ?? ''),
        'midgard_user_id' => (string) ($meta['midgard_user_id'] ?? ''),
        'midgard_provision_state' => (string) ($meta['midgard_provision_state'] ?? ''),
        'midgard_last_error' => (string) ($meta['midgard_last_error'] ?? ''),
        'midgard_password_email_sent_at' => (string) ($meta['midgard_password_email_sent_at'] ?? ''),
    ];
    $metaBlockJson = json_encode($metaBlock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($metaBlockJson === false) {
        $metaBlockJson = '{}';
    }
    $metaBlockEscaped = htmlspecialchars($metaBlockJson, ENT_QUOTES, 'UTF-8');
    $metaBlockField = <<<HTML
<textarea class="form-control" rows="8" readonly style="width:100%;font-family:monospace;">{$metaBlockEscaped}</textarea>
<span class="text-info">&nbsp;&nbsp;Read-only operational metadata.</span>
HTML;

    return [
        'Midgard Server ID' => $serverIdField,
        'Midgard Metadata' => $metaBlockField,
    ];
}

function midgard_AdminServicesTabFieldsSave(array $params): void
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        return;
    }

    $rawModuleFields = $_POST['modulefields'] ?? null;
    if (! is_array($rawModuleFields)) {
        return;
    }

    if (array_key_exists('midgard_server_id', $rawModuleFields)) {
        $targetServerIdRaw = trim((string) $rawModuleFields['midgard_server_id']);
    } elseif (array_key_exists(0, $rawModuleFields)) {
        $targetServerIdRaw = trim((string) $rawModuleFields[0]);
    } else {
        return;
    }
    $store = midgard_store();
    $meta = $store->get($serviceId);
    $previousServerId = (string) ($meta['midgard_server_id'] ?? '');

    if ($targetServerIdRaw === '') {
        $meta['midgard_server_id'] = '';
        $meta['midgard_server_uuid'] = '';
        $meta['midgard_provision_state'] = 'installing';
        $meta['midgard_last_error'] = '';
        $store->upsert($serviceId, $meta);

        midgard_logDiagnostic('admin.serverBindingCleared', [
            'serviceid' => $serviceId,
            'previous_server_id' => $previousServerId,
        ]);
        return;
    }

    if (! ctype_digit($targetServerIdRaw) || (int) $targetServerIdRaw <= 0) {
        $meta['midgard_last_error'] = 'Manual bind failed: Midgard Server ID must be a positive integer.';
        $store->upsert($serviceId, $meta);
        midgard_logDiagnostic('admin.serverBindingRejected.invalidServerId', [
            'serviceid' => $serviceId,
            'requested_server_id' => $targetServerIdRaw,
        ]);
        return;
    }

    $targetServerId = (int) $targetServerIdRaw;
    try {
        $client = midgard_client($params);
        $serverResponse = $client->getServer($targetServerId);
        $serverData = $serverResponse['data'] ?? [];
        if (! is_array($serverData)) {
            throw new \RuntimeException('Invalid server payload returned by Midgard panel.');
        }

        $expectedUserId = (int) ($meta['midgard_user_id'] ?? 0);
        $actualUserId = midgard_extractServerOwnerId($serverData);
        if ($expectedUserId > 0 && $actualUserId > 0 && $expectedUserId !== $actualUserId) {
            $message = "Manual bind failed: server owner mismatch (expected {$expectedUserId}, got {$actualUserId}).";
            $meta['midgard_last_error'] = $message;
            $store->upsert($serviceId, $meta);
            midgard_logDiagnostic('admin.serverBindingRejected.ownerMismatch', [
                'serviceid' => $serviceId,
                'requested_server_id' => $targetServerId,
                'expected_user_id' => $expectedUserId,
                'actual_user_id' => $actualUserId,
            ]);
            return;
        }

        if ($actualUserId > 0 && $expectedUserId <= 0) {
            $meta['midgard_user_id'] = (string) $actualUserId;
        }

        $meta['midgard_server_id'] = (string) $targetServerId;
        $serverUuid = trim((string) ($serverData['uuid'] ?? ''));
        if ($serverUuid !== '') {
            $meta['midgard_server_uuid'] = $serverUuid;
        }
        $meta['midgard_last_error'] = '';
        $store->upsert($serviceId, $meta);

        $synced = SyncService::syncFromPanel($params, $store);
        $store->upsert($serviceId, $synced);

        midgard_logDiagnostic('admin.serverBindingUpdated', [
            'serviceid' => $serviceId,
            'previous_server_id' => $previousServerId,
            'current_server_id' => $targetServerId,
            'expected_user_id' => $expectedUserId,
            'actual_user_id' => $actualUserId,
        ]);
    } catch (\Throwable $e) {
        $meta = $store->get($serviceId);
        $meta['midgard_last_error'] = 'Manual bind failed: ' . $e->getMessage();
        $store->upsert($serviceId, $meta);
        midgard_logDiagnostic('admin.serverBindingRejected.syncError', [
            'serviceid' => $serviceId,
            'requested_server_id' => $targetServerId,
        ], [
            'message' => $e->getMessage(),
        ]);
    }
}

function midgard_RefreshFromPanel(array $params)
{
    $serviceId = (int) ($params['serviceid'] ?? 0);
    $store = midgard_store();
    $meta = $store->get($serviceId);
    $serverId = (int) ($meta['midgard_server_id'] ?? 0);

    if ($serverId <= 0) {
        return 'Refresh blocked: Midgard Server ID is not set for this service.';
    }

    try {
        $synced = SyncService::syncFromPanel($params, $store);
        $store->upsert($serviceId, $synced);
        midgard_logDiagnostic('admin.refreshFromPanel.success', [
            'serviceid' => $serviceId,
            'server_id' => $serverId,
            'provision_state' => (string) ($synced['midgard_provision_state'] ?? ''),
        ]);
        return 'success';
    } catch (\Throwable $e) {
        $meta['midgard_last_error'] = 'Refresh failed: ' . $e->getMessage();
        $store->upsert($serviceId, $meta);
        midgard_logDiagnostic('admin.refreshFromPanel.failed', [
            'serviceid' => $serviceId,
            'server_id' => $serverId,
        ], [
            'message' => $e->getMessage(),
        ]);
        return 'Refresh failed: ' . $e->getMessage();
    }
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
        if (! class_exists(MetadataStore::class, false)) {
            require_once __DIR__ . '/lib/PasswordDispatchStore.php';
            require_once __DIR__ . '/lib/MetadataStore.php';
        }

        if (! class_exists(MetadataStore::class, false)) {
            throw new \RuntimeException('Midgard module runtime is incomplete: MetadataStore class could not be loaded.');
        }

        $store = new MetadataStore();
    }

    return $store;
}

function midgard_generatePassword(int $length = 16): string
{
    return PasswordGenerator::generate();
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

function midgard_resolveHostingStatus(array $params, int $serviceId): string
{
    $status = strtolower(trim((string) ($params['status'] ?? '')));
    if ($status !== '') {
        return $status;
    }

    if ($serviceId <= 0) {
        return '';
    }

    try {
        $dbStatus = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('domainstatus');

        return strtolower(trim((string) ($dbStatus ?? '')));
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * @param array<string, mixed> $serverData
 */
function midgard_extractServerOwnerId(array $serverData): int
{
    $candidates = [
        (int) ($serverData['user_id'] ?? 0),
        (int) ($serverData['owner_id'] ?? 0),
        (int) ($serverData['ownerId'] ?? 0),
        (int) (($serverData['user']['id'] ?? 0)),
        (int) (($serverData['owner']['id'] ?? 0)),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate > 0) {
            return $candidate;
        }
    }

    return 0;
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
