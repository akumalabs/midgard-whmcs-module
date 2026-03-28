<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\ProvisioningNetworkService;
use PHPUnit\Framework\TestCase;

final class ProvisioningNetworkServiceTest extends TestCase
{
    public function test_ensure_primary_ipv4_skips_assignment_when_primary_already_exists(): void
    {
        $serverFetchCalls = 0;
        $availableCalls = 0;
        $assignCalls = 0;
        $setPrimaryCalls = 0;

        $result = ProvisioningNetworkService::ensurePrimaryIpv4WithCallbacks(
            static function (int $serverId) use (&$serverFetchCalls): array {
                $serverFetchCalls++;
                return [
                    'data' => [
                        'id' => $serverId,
                        'addresses' => [
                            ['id' => 1, 'address' => '203.0.113.10', 'type' => 'ipv4', 'is_primary' => true],
                        ],
                    ],
                ];
            },
            static function () use (&$availableCalls): array {
                $availableCalls++;
                return ['data' => []];
            },
            static function () use (&$assignCalls): array {
                $assignCalls++;
                return ['data' => []];
            },
            static function () use (&$setPrimaryCalls): array {
                $setPrimaryCalls++;
                return ['data' => []];
            },
            100
        );

        $this->assertTrue($result['ensured']);
        $this->assertFalse($result['attempted']);
        $this->assertFalse($result['assigned']);
        $this->assertSame('203.0.113.10', $result['primary_ipv4']);
        $this->assertSame('', $result['error']);
        $this->assertSame(1, $serverFetchCalls);
        $this->assertSame(0, $availableCalls);
        $this->assertSame(0, $assignCalls);
        $this->assertSame(0, $setPrimaryCalls);
    }

    public function test_ensure_primary_ipv4_assigns_first_available_ipv4_and_sets_primary(): void
    {
        $serverFetchCalls = 0;
        $assignedAddressId = 0;
        $primaryAddressId = 0;

        $result = ProvisioningNetworkService::ensurePrimaryIpv4WithCallbacks(
            static function (int $serverId) use (&$serverFetchCalls): array {
                $serverFetchCalls++;
                if ($serverFetchCalls === 1) {
                    return [
                        'data' => [
                            'id' => $serverId,
                            'addresses' => [],
                        ],
                    ];
                }

                return [
                    'data' => [
                        'id' => $serverId,
                        'addresses' => [
                            ['id' => 22, 'address' => '198.51.100.22', 'type' => 'ipv4', 'is_primary' => true],
                        ],
                    ],
                ];
            },
            static function (): array {
                return [
                    'data' => [
                        ['id' => 99, 'address' => '2001:db8::99', 'type' => 'ipv6'],
                        ['id' => 22, 'address' => '198.51.100.22', 'type' => 'ipv4'],
                    ],
                ];
            },
            static function (int $serverId, int $addressId) use (&$assignedAddressId): array {
                $assignedAddressId = $addressId;
                return ['data' => ['server_id' => $serverId, 'address_id' => $addressId]];
            },
            static function (int $serverId, int $addressId) use (&$primaryAddressId): array {
                $primaryAddressId = $addressId;
                return ['data' => ['server_id' => $serverId, 'address_id' => $addressId]];
            },
            200
        );

        $this->assertTrue($result['ensured']);
        $this->assertTrue($result['attempted']);
        $this->assertTrue($result['assigned']);
        $this->assertSame('198.51.100.22', $result['primary_ipv4']);
        $this->assertSame('', $result['error']);
        $this->assertSame(22, $assignedAddressId);
        $this->assertSame(22, $primaryAddressId);
        $this->assertSame(2, $serverFetchCalls);
    }

    public function test_ensure_primary_ipv4_returns_failure_when_assignment_path_throws(): void
    {
        $result = ProvisioningNetworkService::ensurePrimaryIpv4WithCallbacks(
            static function (): array {
                return [
                    'data' => [
                        'addresses' => [],
                    ],
                ];
            },
            static function (): array {
                return [
                    'data' => [
                        ['id' => 5, 'address' => '203.0.113.5', 'type' => 'ipv4'],
                    ],
                ];
            },
            static function (): array {
                throw new \RuntimeException('assign failed');
            },
            static function (): array {
                return ['data' => []];
            },
            300
        );

        $this->assertFalse($result['ensured']);
        $this->assertFalse($result['assigned']);
        $this->assertSame('', $result['primary_ipv4']);
        $this->assertStringContainsString('assign failed', $result['error']);
    }
}
