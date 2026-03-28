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
     *   primary_ipv4: string,
     *   error: string
     * }
     */
    public static function ensurePrimaryIpv4(ApiClient $client, int $serverId): array
    {
        return self::ensurePrimaryIpv4WithCallbacks(
            static fn (int $id): array => $client->getServer($id),
            static fn (int $id): array => $client->availableIPs($id),
            static fn (int $id, int $addressId): array => $client->assignIP($id, $addressId),
            static fn (int $id, int $addressId): array => $client->setPrimaryIP($id, $addressId),
            $serverId
        );
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
     *   primary_ipv4: string,
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
        try {
            $serverResponse = $fetchServer($serverId);
            $primaryIpv4 = self::extractPrimaryIpv4FromServerResponse($serverResponse);

            if ($primaryIpv4 !== '') {
                return [
                    'ensured' => true,
                    'attempted' => false,
                    'assigned' => false,
                    'primary_ipv4' => $primaryIpv4,
                    'error' => '',
                ];
            }

            $availableIpsResponse = $fetchAvailableIps($serverId);
            $addressId = self::pickFirstAvailableIpv4AddressId($availableIpsResponse);
            if ($addressId <= 0) {
                return [
                    'ensured' => false,
                    'attempted' => false,
                    'assigned' => false,
                    'primary_ipv4' => '',
                    'error' => 'No available IPv4 addresses were returned by panel.',
                ];
            }

            $assignIp($serverId, $addressId);
            $setPrimaryIp($serverId, $addressId);

            $updatedServerResponse = $fetchServer($serverId);
            $updatedPrimaryIpv4 = self::extractPrimaryIpv4FromServerResponse($updatedServerResponse);

            if ($updatedPrimaryIpv4 === '') {
                return [
                    'ensured' => false,
                    'attempted' => true,
                    'assigned' => true,
                    'primary_ipv4' => '',
                    'error' => 'IPv4 assignment was attempted but panel still reports no primary IPv4.',
                ];
            }

            return [
                'ensured' => true,
                'attempted' => true,
                'assigned' => true,
                'primary_ipv4' => $updatedPrimaryIpv4,
                'error' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'ensured' => false,
                'attempted' => false,
                'assigned' => false,
                'primary_ipv4' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $serverResponse
     */
    public static function extractPrimaryIpv4FromServerResponse(array $serverResponse): string
    {
        $serverData = $serverResponse['data'] ?? null;
        if (! is_array($serverData)) {
            return '';
        }

        $addresses = $serverData['addresses'] ?? null;
        if (! is_array($addresses)) {
            return '';
        }

        foreach ($addresses as $row) {
            if (! is_array($row)) {
                continue;
            }

            $isPrimary = (bool) ($row['is_primary'] ?? false);
            if (! $isPrimary) {
                continue;
            }

            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $address = trim((string) ($row['address'] ?? ''));
            if ($type === 'ipv4' && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $availableIpsResponse
     */
    private static function pickFirstAvailableIpv4AddressId(array $availableIpsResponse): int
    {
        $rows = $availableIpsResponse['data'] ?? null;
        if (! is_array($rows)) {
            return 0;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $address = trim((string) ($row['address'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            $id = (int) ($row['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            if ($type === 'ipv4' || ($type === '' && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return $id;
                }
            }
        }

        return 0;
    }
}
