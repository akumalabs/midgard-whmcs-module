<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

final class SyncService
{
    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function syncFromPanel(array $params, MetadataStore $store): array
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $meta = $store->get($serviceId);

        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return $meta;
        }

        $client = new ApiClient(Config::panelBaseUrl($params), Config::apiToken($params));

        $progressPayload = [];
        try {
            $progressPayload = $client->installProgress($serverId);
        } catch (\Throwable $e) {
            // Keep existing state when progress endpoint is temporarily unavailable.
        }

        if ($progressPayload !== []) {
            $mapped = ProvisionStateMapper::fromInstallProgress($progressPayload);
            $meta['midgard_provision_state'] = $mapped['state'];
            $meta['midgard_last_error'] = $mapped['error'];
        }

        try {
            $serverResponse = $client->getServer($serverId);
            $serverData = $serverResponse['data'] ?? [];
            if (is_array($serverData)) {
                $serverName = trim((string) ($serverData['name'] ?? ''));
                $hostname = trim((string) ($serverData['hostname'] ?? ''));
                $serverUuid = trim((string) ($serverData['uuid'] ?? ''));
                $serverOwnerId = self::extractServerOwnerId($serverData);
                $networkSummary = self::extractNetworkSummary($serverData);
                $liveResourceSummary = self::extractLiveResourceSummary($serverData);

                $meta['midgard_addresses'] = $networkSummary['addresses'];
                $meta['midgard_primary_ipv4'] = $networkSummary['primary_ipv4'];
                $meta['midgard_primary_ipv6'] = $networkSummary['primary_ipv6'];
                $meta['midgard_live_cpu'] = $liveResourceSummary['cpu'];
                $meta['midgard_live_memory'] = $liveResourceSummary['memory'];
                $meta['midgard_live_disk'] = $liveResourceSummary['disk'];
                $meta['midgard_live_bandwidth_limit'] = $liveResourceSummary['bandwidth_limit'];
                $meta['midgard_live_backup_limit'] = $liveResourceSummary['backup_limit'];
                $meta['midgard_live_snapshot_limit'] = $liveResourceSummary['snapshot_limit'];

                if ($serverOwnerId > 0) {
                    $meta['midgard_user_id'] = (string) $serverOwnerId;
                }

                if ($serverName !== '' && $hostname !== '') {
                    self::syncHostingIdentity($serviceId, $serverName, $hostname);
                }
                self::syncHostingNetwork($serviceId, $networkSummary);

                if ($serverUuid !== '') {
                    $meta['midgard_server_uuid'] = $serverUuid;
                }

                if (($meta['midgard_provision_state'] ?? '') === '' || $progressPayload === []) {
                    $statusMapped = ProvisionStateMapper::fromServerStatus($serverData);
                    $meta['midgard_provision_state'] = $statusMapped['state'];
                    if (trim($meta['midgard_last_error']) === '') {
                        $meta['midgard_last_error'] = $statusMapped['error'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore detail sync failures in cron/client area fallback.
        }

        $store->upsert($serviceId, $meta);
        return $meta;
    }

    public static function syncHostingIdentity(int $serviceId, string $serverName, string $hostname): void
    {
        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'username' => $serverName,
                'domain' => $hostname,
            ]);
    }

    /**
     * @param array{
     *   addresses: array<int, array{id: int, address: string, type: string, is_primary: bool}>,
     *   primary_ipv4: string,
     *   primary_ipv6: string
     * } $networkSummary
     */
    public static function syncHostingNetwork(int $serviceId, array $networkSummary): void
    {
        $mapped = self::mapHostingNetworkFields($networkSummary);

        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update([
                'dedicatedip' => $mapped['dedicatedip'],
                'assignedips' => $mapped['assignedips'],
            ]);
    }

    /**
     * @param array{
     *   addresses: array<int, array{id: int, address: string, type: string, is_primary: bool}>,
     *   primary_ipv4: string,
     *   primary_ipv6: string
     * } $networkSummary
     * @return array{dedicatedip: string, assignedips: string}
     */
    public static function mapHostingNetworkFields(array $networkSummary): array
    {
        $primaryIpv4 = trim((string) ($networkSummary['primary_ipv4'] ?? ''));
        $primaryIpv6 = trim((string) ($networkSummary['primary_ipv6'] ?? ''));
        $dedicatedIp = $primaryIpv4 !== '' ? $primaryIpv4 : $primaryIpv6;

        $assignedIps = [];
        $addresses = $networkSummary['addresses'] ?? [];
        if (is_array($addresses)) {
            foreach ($addresses as $addressRow) {
                if (! is_array($addressRow)) {
                    continue;
                }
                $address = trim((string) ($addressRow['address'] ?? ''));
                if ($address !== '') {
                    $assignedIps[] = $address;
                }
            }
        }

        return [
            'dedicatedip' => $dedicatedIp,
            'assignedips' => implode("\n", $assignedIps),
        ];
    }

    /**
     * @param array<string, int|float> $configSpecs
     * @param array<string, mixed> $meta
     * @return array<string, int|float>
     */
    public static function buildSpecsForClientArea(array $configSpecs, array $meta): array
    {
        $cpu = self::nullableInt($meta['midgard_live_cpu'] ?? null);
        $memoryBytes = self::nullableInt($meta['midgard_live_memory'] ?? null);
        $diskBytes = self::nullableInt($meta['midgard_live_disk'] ?? null);
        $bandwidthBytes = self::nullableInt($meta['midgard_live_bandwidth_limit'] ?? null);
        $backupLimit = self::nullableInt($meta['midgard_live_backup_limit'] ?? null);
        $snapshotLimit = self::nullableInt($meta['midgard_live_snapshot_limit'] ?? null);

        return [
            'cpu' => $cpu ?? (int) ($configSpecs['cpu'] ?? 1),
            'memory_gb' => $memoryBytes !== null
                ? self::bytesToGigabytes($memoryBytes)
                : ($configSpecs['memory_gb'] ?? 1),
            'disk_gb' => $diskBytes !== null
                ? self::bytesToGigabytes($diskBytes)
                : ($configSpecs['disk_gb'] ?? 10),
            'bandwidth_tb' => $bandwidthBytes !== null
                ? self::bytesToTerabytes($bandwidthBytes)
                : ($configSpecs['bandwidth_tb'] ?? 1),
            'backup_limit' => $backupLimit ?? (int) ($configSpecs['backup_limit'] ?? 0),
            'snapshot_limit' => $snapshotLimit ?? (int) ($configSpecs['snapshot_limit'] ?? 0),
            'os_image_id' => (int) ($configSpecs['os_image_id'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $serverData
     * @return array{
     *   addresses: array<int, array{id: int, address: string, type: string, is_primary: bool}>,
     *   primary_ipv4: string,
     *   primary_ipv6: string
     * }
     */
    private static function extractNetworkSummary(array $serverData): array
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
     * @param array<string, mixed> $serverData
     * @return array{
     *   cpu: int|null,
     *   memory: int|null,
     *   disk: int|null,
     *   bandwidth_limit: int|null,
     *   backup_limit: int|null,
     *   snapshot_limit: int|null
     * }
     */
    private static function extractLiveResourceSummary(array $serverData): array
    {
        return [
            'cpu' => self::nullableInt($serverData['cpu'] ?? null),
            'memory' => self::nullableInt($serverData['memory'] ?? null),
            'disk' => self::nullableInt($serverData['disk'] ?? null),
            'bandwidth_limit' => self::nullableInt($serverData['bandwidth_limit'] ?? null),
            'backup_limit' => self::nullableInt($serverData['backup_limit'] ?? null),
            'snapshot_limit' => self::nullableInt($serverData['snapshot_limit'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $serverData
     */
    private static function extractServerOwnerId(array $serverData): int
    {
        $ownerId = (int) ($serverData['ownerId'] ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }

        $ownerRaw = $serverData['owner'] ?? null;
        if (is_array($ownerRaw)) {
            $ownerId = (int) ($ownerRaw['id'] ?? 0);
            if ($ownerId > 0) {
                return $ownerId;
            }
        }

        return 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private static function bytesToGigabytes(int $bytes): float
    {
        return round($bytes / 1024 / 1024 / 1024, 2);
    }

    private static function bytesToTerabytes(int $bytes): float
    {
        return round($bytes / 1024 / 1024 / 1024 / 1024, 2);
    }
}
