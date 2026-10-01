<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

final class SyncService
{
    /**
     * Credentials-email gate: resolve the server's LIVE provision state
     * from the panel and decide whether the welcome email may go out.
     * Called by the AfterCronJob flush worker before every send attempt.
     *
     * @param array<string, mixed> $params
     * @return array{send: bool, reason: string}
     */
    public static function credentialsEmailGate(array $params, MetadataStore $store): array
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $meta = $store->get($serviceId);

        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return ['send' => false, 'reason' => 'provision_state_unknown'];
        }

        try {
            $client = new ApiClient(Config::panelBaseUrl($params), Config::apiToken($params), TokenInfoStore::resolveBasePath($params));
            $serverResponse = $client->getServer($serverId);
        } catch (\Throwable $e) {
            return ['send' => false, 'reason' => 'panel_unreachable: ' . $e->getMessage()];
        }

        $serverData = is_array($serverResponse['data'] ?? null) ? $serverResponse['data'] : [];
        $mapped = ProvisionStateMapper::fromServerStatus($serverData);

        if ($mapped['state'] === 'failed') {
            return ['send' => false, 'reason' => 'provision_failed'];
        }

        return ProvisionGate::evaluate($mapped['state']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function syncFromPanel(array $params, MetadataStore $store, bool $includeProgress = true): array
    {
        $serviceId = (int) ($params['serviceid'] ?? 0);
        $meta = $store->get($serviceId);

        $serverId = (int) ($meta['midgard_server_id'] ?? 0);
        if ($serverId <= 0) {
            return $meta;
        }

        $client = new ApiClient(Config::panelBaseUrl($params), Config::apiToken($params), TokenInfoStore::resolveBasePath($params));

        $progressPayload = [];
        if ($includeProgress) {
            try {
                $progressPayload = $client->installProgress($serverId);
            } catch (\Throwable $e) {
                // Keep existing state when progress endpoint is temporarily unavailable.
            }
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
                // Task-first: an in-flight install task outranks the VM's
                // (possibly stale) power status, so a rebuild on a running
                // server cannot write back RUNNING before the task claims
                // the VM. Terminal/absent tasks defer to the power status.
                $meta['midgard_runtime_status'] = self::effectiveRuntimeStatus(
                    (string) ($progressPayload['status'] ?? ''),
                    self::normalizeRuntimeStatus($serverData['status'] ?? null),
                    self::normalizeRuntimeStatus($meta['midgard_runtime_status'] ?? 'unknown'),
                    strtolower(trim((string) ($meta['midgard_provision_state'] ?? '')))
                );
                $statsJson = self::extractStatsSnapshot($serverData);
                if ($statsJson !== null) {
                    $meta['midgard_stats_json'] = $statsJson;
                }

                // Current OS image name (panel payload: os_image.{id,name,distro,version}).
                $osImage = $serverData['os_image'] ?? null;
                if (is_array($osImage) && isset($osImage['name']) && (string) $osImage['name'] !== '') {
                    $meta['midgard_os_name'] = (string) $osImage['name'];
                }

                // VMID + location (panel payload: vmid, node.{name, location.{name, short_code}}).
                $vmid = trim((string) ($serverData['vmid'] ?? ''));
                if ($vmid !== '') {
                    $meta['midgard_vmid'] = $vmid;
                }
                $locationName = '';
                $node = $serverData['node'] ?? null;
                if (is_array($node)) {
                    $nodeLocation = $node['location'] ?? null;
                    if (is_array($nodeLocation)) {
                        $locationName = trim((string) ($nodeLocation['name'] ?? ''));
                    }
                    if ($locationName === '') {
                        $locationName = trim((string) ($node['name'] ?? ''));
                    }
                }
                if ($locationName !== '') {
                    $meta['midgard_location'] = $locationName;
                }

                if ($serverOwnerId > 0) {
                    $meta['midgard_user_id'] = (string) $serverOwnerId;
                }
                if ($serverName !== '') {
                    $meta['midgard_server_name'] = $serverName;
                }
                if ($hostname !== '') {
                    $meta['midgard_server_hostname'] = $hostname;
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
     * Hydrate module metadata from an API server payload WITHOUT any extra
     * HTTP round-trip. Used right after createServer(): the panel's 201
     * response already carries the formatted server (addresses included),
     * so the sequential sync/ensure chain can be skipped on the happy path.
     * Returns the network summary so callers can decide whether a legacy
     * fallback chain is still required (e.g. a required IPv4 that never
     * materialized).
     */
    public static function hydrateFromServerData(int $serviceId, array $serverData, MetadataStore $store): array
    {
        if ($serverData === []) {
            return ['addresses' => [], 'primary_ipv4' => '', 'primary_ipv6' => '', 'primary_ipv6_subnet' => ''];
        }

        $networkSummary = self::extractNetworkSummary($serverData);
        if ($networkSummary['addresses'] === [] && $networkSummary['primary_ipv4'] === '' && $networkSummary['primary_ipv6'] === '') {
            return $networkSummary;
        }

        $meta = $store->get($serviceId);
        $meta['midgard_addresses'] = $networkSummary['addresses'];
        $meta['midgard_primary_ipv4'] = $networkSummary['primary_ipv4'];
        $meta['midgard_primary_ipv6'] = $networkSummary['primary_ipv6'];
        $serverName = trim((string) ($serverData['name'] ?? ''));
        $hostname = trim((string) ($serverData['hostname'] ?? ''));
        if ($serverName !== '') {
            $meta['midgard_server_name'] = $serverName;
        }
        if ($hostname !== '') {
            $meta['midgard_server_hostname'] = $hostname;
        }
        $store->upsert($serviceId, $meta);

        try {
            self::syncHostingNetwork($serviceId, $networkSummary);
            if ($serverName !== '' && $hostname !== '') {
                self::syncHostingIdentity($serviceId, $serverName, $hostname);
            }
        } catch (\Throwable $e) {
            // WHMCS table writes are cosmetic here (tblhosting mirrors);
            // hydration must keep working in contexts without them (tests).
        }

        return $networkSummary;
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
        // Live 2026-10-01 (Master): the native WHMCS IP columns carry the
        // REAL addresses again — they power the stock product-details page
        // and make services searchable by IP in admin lists. The module's
        // own Server Overview grid stays the client-facing display; rounds
        // 11/12 emptied these columns only to kill STALE native rows, which
        // is moot now that the columns are written live from every sync.
        $fields = self::mapHostingNetworkFields($networkSummary);

        Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->update($fields);
    }

    public static function resetHostingNetwork(int $serviceId): void
    {
        self::resetHostingNetworkWithUpdater(
            $serviceId,
            static function (int $targetServiceId, array $fields): void {
                Capsule::table('tblhosting')
                    ->where('id', $targetServiceId)
                    ->update($fields);
            }
        );
    }

    /**
     * @param callable(int, array{dedicatedip: string, assignedips: string}): void $updater
     */
    public static function resetHostingNetworkWithUpdater(int $serviceId, callable $updater): void
    {
        if ($serviceId <= 0) {
            return;
        }

        $updater($serviceId, self::emptyHostingNetworkFields());
    }

    /**
     * @return array{dedicatedip: string, assignedips: string}
     */
    public static function emptyHostingNetworkFields(): array
    {
        return [
            'dedicatedip' => '',
            'assignedips' => '',
        ];
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
                $isPrimary = (bool) ($addressRow['is_primary'] ?? false);
                if ($isPrimary) {
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
        $primaryIpv6Subnet = '';

        if (! is_array($addressesRaw)) {
            return [
                'addresses' => [],
                'primary_ipv4' => '',
                'primary_ipv6' => '',
                'primary_ipv6_subnet' => '',
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
            $isSubnet = (bool) ($row['is_subnet'] ?? false);

            if ($address === '' || ! in_array($type, ['ipv4', 'ipv6'], true)) {
                continue;
            }

            if ($type === 'ipv6' && $isSubnet) {
                // Subnet-level IPv6 row: prefer the individual /128 hosts.
                // Expand ipv6_individuals[] into the addresses list so email
                // templates render routable hosts instead of the bare /64.
                $primaryIpv6Subnet = $address;
                if ($isPrimary && $primaryIpv6Subnet === '') {
                    $primaryIpv6Subnet = $address;
                }

                $individuals = $row['ipv6_individuals'] ?? null;
                if (is_array($individuals)) {
                    foreach ($individuals as $individual) {
                        if (! is_array($individual)) {
                            continue;
                        }
                        $indAddress = trim((string) ($individual['address'] ?? ''));
                        if ($indAddress === '') {
                            continue;
                        }
                        $indIsPrimary = (bool) ($individual['is_primary'] ?? false);

                        $addresses[] = [
                            'id' => (int) ($individual['id'] ?? 0),
                            'address' => $indAddress,
                            'type' => 'ipv6',
                            'is_primary' => $indIsPrimary,
                        ];

                        if ($indIsPrimary && $primaryIpv6 === '') {
                            $primaryIpv6 = $indAddress;
                        }
                    }
                    continue;
                }
                // No individuals supplied: fall through to legacy behaviour
                // and surface the subnet as a regular row.
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

        // Legacy fallback: scan all addresses for ANY IPv6 when none flagged
        // primary. This preserves behaviour for panels that have not yet
        // emitted is_subnet / ipv6_individuals metadata.
        if ($primaryIpv6 === '') {
            foreach ($addresses as $addressRow) {
                if (($addressRow['type'] ?? '') === 'ipv6') {
                    $primaryIpv6 = $addressRow['address'];
                    break;
                }
            }
        }

        // Final fallback: use the top-level primary_ipv6_individual field
        // exposed by the panel (formatServer) when the /64 subnet is the
        // assigned address but IPv4 holds is_primary priority.
        if ($primaryIpv6 === '') {
            $topLevelIndividual = $serverData['primary_ipv6_individual'] ?? null;
            if (is_array($topLevelIndividual)) {
                $individualAddress = trim((string) ($topLevelIndividual['address'] ?? ''));
                if ($individualAddress !== '') {
                    $primaryIpv6 = $individualAddress;
                }
            }
        }

        return [
            'addresses' => $addresses,
            'primary_ipv4' => $primaryIpv4,
            'primary_ipv6' => $primaryIpv6,
            'primary_ipv6_subnet' => $primaryIpv6Subnet,
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
     * Latest collector snapshot (panel `stats` block) as a canonical JSON
     * string for meta persistence. Null when the panel did not include it
     * (old panel version / no snapshot yet) so an existing stored snapshot
     * is never wiped by a payload that simply lacks the field.
     *
     * @param array<string, mixed> $serverData
     */
    private static function extractStatsSnapshot(array $serverData): ?string
    {
        $stats = $serverData['stats'] ?? null;
        if (! is_array($stats) || $stats === []) {
            return null;
        }

        $known = ['status', 'uptime', 'cpu_percent', 'mem', 'maxmem', 'disk', 'maxdisk', 'collected_at'];
        $clean = [];
        foreach ($known as $key) {
            if (array_key_exists($key, $stats)) {
                $clean[$key] = $stats[$key];
            }
        }

        // Month-to-date bandwidth lives at the payload root (not inside the
        // panel `stats` block) — ride it along so the client area Bandwidth
        // card shows real usage against the limit.
        if (array_key_exists('bandwidth_usage', $serverData)) {
            $clean['bandwidth_usage'] = $serverData['bandwidth_usage'];
        }

        $encoded = json_encode($clean);
        if ($encoded === false || $encoded === '[]') {
            return null;
        }

        return $encoded;
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

    private static function normalizeRuntimeStatus(mixed $status): string
    {
        $normalized = strtolower(trim((string) $status));
        if ($normalized === '') {
            return 'unknown';
        }

        return $normalized;
    }

    /**
     * Effective client-facing runtime status: the INSTALL TASK is the
     * authoritative rebuild signal, the server power status is secondary.
     * Right after a rebuild is accepted the VM still reports its OLD power
     * state for a while (the task has not claimed it yet), so trusting
     * server.status alone flashes RUNNING mid-rebuild. Any non-empty task
     * status that is NOT a terminal value counts as in-flight; whether the
     * client label reads INSTALLING (first provisioning) or REBUILDING
     * (re-install of a provisioned server) follows the previous status.
     *
     * @return string effective runtime status (lowercased)
     */
    public static function effectiveRuntimeStatus(
        string $taskStatus,
        string $serverStatus,
        string $previousStatus = '',
        string $provisionState = ''
    ): string {
        $task = strtolower(trim($taskStatus));
        $server = strtolower(trim($serverStatus));
        $previous = strtolower(trim($previousStatus));

        $terminal = ['completed', 'ready', 'succeeded', 'success', 'ok', 'failed', 'error', 'cancelled', 'canceled'];

        if ($task !== '' && ! in_array($task, $terminal, true)) {
            // First-time provisioning keeps the INSTALLING label; anything
            // else in-flight is a rebuild of an existing server. The
            // midgard_provision_state column is the lifecycle discriminator
            // (default 'installing' at create, 'ready' after the first
            // completion — a rebuild never returns to 'installing'); the
            // previous runtime status stays as the BC fallback for callers
            // that do not pass the column. Live-fit finding 2026-10-01: a
            // brand-new create had empty runtime meta ('unknown' fallback)
            // and was labelled REBUILDING.
            if ($provisionState === 'installing'
                || ($provisionState === '' && $previous === 'installing')) {
                return 'installing';
            }

            return 'rebuilding';
        }

        if ($task === 'failed' || $task === 'error') {
            // The install died: surface the real power state (a failed
            // install leaves the VM stopped); blank server status falls
            // back to stopped so the badge never claims RUNNING.
            return $server !== '' && $server !== 'running' ? $server : 'stopped';
        }

        if ($server !== '') {
            return $server;
        }

        return $previous !== '' ? $previous : 'unknown';
    }
}
