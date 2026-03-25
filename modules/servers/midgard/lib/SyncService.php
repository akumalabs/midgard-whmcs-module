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
}
