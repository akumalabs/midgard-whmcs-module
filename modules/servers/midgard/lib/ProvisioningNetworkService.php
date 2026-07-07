<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class ProvisioningNetworkService
{
    /**
     * Ensure the server has a primary IPv4 address.
     *
     * @return array{
     *   ensured: bool,
     *   attempted: bool,
     *   assigned: bool,
     *   primary_ip: string,
     *   error: string
     * }
     */
    public static function ensurePrimaryIpv4(ApiClient $client, int $serverId): array
    {
        $result = self::ensurePrimaryIpWithCallbacks(
            static fn (int $id): array => $client->getServer($id),
            static fn (int $id): array => $client->availableIPs($id, 'ipv4', 200),
            static fn (int $id, int $addressId): array => $client->assignIP($id, $addressId),
            static fn (int $id, int $addressId): array => $client->setPrimaryIP($id, $addressId),
            $serverId,
            'ipv4'
        );

        // Backward-compatible key alias
        $result['primary_ipv4'] = $result['primary_ip'];

        return $result;
    }

    /**
     * Ensure the server has a primary IPv6 subnet (/64) assigned.
     *
     * The panel auto-bootstraps a /128 individual from the assigned /64,
     * making it immediately routable for cloud-init.
     *
     * This is best-effort: failures are returned but should NOT block
     * the overall provisioning flow (non-blocking enforcement).
     *
     * @return array{
     *   ensured: bool,
     *   attempted: bool,
     *   assigned: bool,
     *   primary_ip: string,
     *   error: string
     * }
     */
    public static function ensurePrimaryIpv6(ApiClient $client, int $serverId): array
    {
        return self::ensurePrimaryIpWithCallbacks(
            static fn (int $id): array => $client->getServer($id),
            static fn (int $id): array => $client->availableIPs($id, 'ipv6', 200),
            static fn (int $id, int $addressId): array => $client->assignIP($id, $addressId),
            static fn (int $id, int $addressId): array => $client->setPrimaryIP($id, $addressId),
            $serverId,
            'ipv6'
        );
    }

    /**
     * Normalize the primary IP assignment on the panel using canonical priority.
     *
     * This MUST be called once after all sequential ensurePrimaryIpv4/ensurePrimaryIpv6
     * assignments complete. It delegates to the panel's authoritative endpoint which
     * enforces: IPv4 > IPv6 subnet priority, and syncs the result to Proxmox cloud-init.
     *
     * Non-blocking: failures are logged but do not abort the caller's flow.
     *
     * @return array{
     *   normalized: bool,
     *   primary_ip: string,
     *   error: string
     * }
     */
    public static function normalizePrimaryIp(ApiClient $client, int $serverId): array
    {
        try {
            $response = $client->normalizePrimaryIp($serverId);
            $data = $response['data'] ?? null;

            if (! is_array($data)) {
                return [
                    'normalized' => false,
                    'primary_ip' => '',
                    'error' => 'Panel normalize-primary-ip returned no data.',
                ];
            }

            $address = trim((string) ($data['address'] ?? ''));

            return [
                'normalized' => true,
                'primary_ip' => $address,
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'normalized' => false,
                'primary_ip' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param callable(int): array<string, mixed> $fetchServer
     * @param callable(int): array<string, mixed> $fetchAvailableIps
     * @param callable(int, int): array<string, mixed> $assignIp
     * @param callable(int, int): array<string, mixed> $setPrimaryIp
     * @return array{
     *   ensured: bool,
     *   attempted: bool,
     *   assigned: bool,
     *   primary_ip: string,
     *   error: string
     * }
     */
    public static function ensurePrimaryIpv4WithCallbacks(
        callable $fetchServer,
        callable $fetchAvailableIps,
        callable $assignIp,
        callable $setPrimaryIp,
        int $serverId
    ): array {
        $result = self::ensurePrimaryIpWithCallbacks(
            $fetchServer,
            $fetchAvailableIps,
            $assignIp,
            $setPrimaryIp,
            $serverId,
            'ipv4'
        );

        // Backward-compatible key alias
        $result['primary_ipv4'] = $result['primary_ip'];

        return $result;
    }

    /**
     * Type-agnostic core: ensure the server has a primary IP of the given type.
     *
     * @param callable(int): array<string, mixed> $fetchServer
     * @param callable(int): array<string, mixed> $fetchAvailableIps
     * @param callable(int, int): array<string, mixed> $assignIp
     * @param callable(int, int): array<string, mixed> $setPrimaryIp
     * @param string $type 'ipv4' or 'ipv6'
     * @return array{
     *   ensured: bool,
     *   attempted: bool,
     *   assigned: bool,
     *   primary_ip: string,
     *   error: string
     * }
     */
    public static function ensurePrimaryIpWithCallbacks(
        callable $fetchServer,
        callable $fetchAvailableIps,
        callable $assignIp,
        callable $setPrimaryIp,
        int $serverId,
        string $type
    ): array {
        try {
            $serverResponse = $fetchServer($serverId);
            $primaryIp = self::extractPrimaryIpFromServerResponse($serverResponse, $type);

            if ($primaryIp !== '') {
                return [
                    'ensured' => true,
                    'attempted' => false,
                    'assigned' => false,
                    'primary_ip' => $primaryIp,
                    'error' => '',
                ];
            }

            $availableIpsResponse = $fetchAvailableIps($serverId);
            $addressId = self::pickFirstAvailableAddressId($availableIpsResponse, $type);
            if ($addressId <= 0) {
                return [
                    'ensured' => false,
                    'attempted' => false,
                    'assigned' => false,
                    'primary_ip' => '',
                    'error' => "No available {$type} addresses were returned by panel.",
                ];
            }

            $assignIp($serverId, $addressId);
            $setPrimaryIp($serverId, $addressId);

            $updatedServerResponse = $fetchServer($serverId);
            $updatedPrimaryIp = self::extractPrimaryIpFromServerResponse($updatedServerResponse, $type);

            if ($updatedPrimaryIp === '') {
                return [
                    'ensured' => false,
                    'attempted' => true,
                    'assigned' => true,
                    'primary_ip' => '',
                    'error' => "{$type} assignment was attempted but panel still reports no primary {$type}.",
                ];
            }

            return [
                'ensured' => true,
                'attempted' => true,
                'assigned' => true,
                'primary_ip' => $updatedPrimaryIp,
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'ensured' => false,
                'attempted' => false,
                'assigned' => false,
                'primary_ip' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    // ------------------------------------------------------------------
    // Backward-compatible aliases (legacy field names)
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $serverResponse
     */
    public static function extractPrimaryIpv4FromServerResponse(array $serverResponse): string
    {
        return self::extractPrimaryIpFromServerResponse($serverResponse, 'ipv4');
    }

    // ------------------------------------------------------------------
    // Type-agnostic extraction helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $serverResponse
     * @param string $type 'ipv4' or 'ipv6'
     */
    public static function extractPrimaryIpFromServerResponse(array $serverResponse, string $type): string
    {
        $serverData = $serverResponse['data'] ?? null;
        if (! is_array($serverData)) {
            return '';
        }

        $addresses = $serverData['addresses'] ?? null;
        if (! is_array($addresses)) {
            return '';
        }

        $flag = $type === 'ipv6' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

        foreach ($addresses as $row) {
            if (! is_array($row)) {
                continue;
            }

            $isPrimary = (bool) ($row['is_primary'] ?? false);
            if (! $isPrimary) {
                continue;
            }

            $rowType = strtolower(trim((string) ($row['type'] ?? '')));
            $address = trim((string) ($row['address'] ?? ''));
            if ($rowType === $type && filter_var($address, FILTER_VALIDATE_IP, $flag) !== false) {
                return $address;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $availableIpsResponse
     * @param string $type 'ipv4' or 'ipv6'
     */
    private static function pickFirstAvailableAddressId(array $availableIpsResponse, string $type): int
    {
        $rows = $availableIpsResponse['data'] ?? null;
        if (! is_array($rows)) {
            return 0;
        }

        $flag = $type === 'ipv6' ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $address = trim((string) ($row['address'] ?? ''));
            $rowType = strtolower(trim((string) ($row['type'] ?? '')));
            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($rowType === $type || ($rowType === '' && filter_var($address, FILTER_VALIDATE_IP, $flag) !== false)) {
                if (filter_var($address, FILTER_VALIDATE_IP, $flag) !== false) {
                    return $id;
                }
            }
        }

        return 0;
    }
}
