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
                $networkSummary = self::extractNetworkSummary($serverData);
                $meta['midgard_addresses'] = $networkSummary['addresses'];
                $meta['midgard_primary_ipv4'] = $networkSummary['primary_ipv4'];
                $meta['midgard_primary_ipv6'] = $networkSummary['primary_ipv6'];

                if ($serverName !== '' && $hostname !== '') {
                    self::syncHostingIdentity($serviceId, $serverName, $hostname);
                }

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
}
