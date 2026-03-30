<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\SyncService;
use PHPUnit\Framework\TestCase;

final class SyncServiceTest extends TestCase
{
    public function test_map_hosting_network_fields_primary_only_keeps_assigned_ips_empty(): void
    {
        $mapped = SyncService::mapHostingNetworkFields([
            'addresses' => [
                ['id' => 1, 'address' => '203.0.113.10', 'type' => 'ipv4', 'is_primary' => true],
            ],
            'primary_ipv4' => '203.0.113.10',
            'primary_ipv6' => '',
        ]);

        $this->assertSame('203.0.113.10', $mapped['dedicatedip']);
        $this->assertSame('', $mapped['assignedips']);
    }

    public function test_map_hosting_network_fields_primary_plus_extra_ips_lists_only_extras(): void
    {
        $mapped = SyncService::mapHostingNetworkFields([
            'addresses' => [
                ['id' => 1, 'address' => '203.0.113.10', 'type' => 'ipv4', 'is_primary' => true],
                ['id' => 2, 'address' => '203.0.113.11', 'type' => 'ipv4', 'is_primary' => false],
                ['id' => 3, 'address' => '2001:db8::10', 'type' => 'ipv6', 'is_primary' => false],
            ],
            'primary_ipv4' => '203.0.113.10',
            'primary_ipv6' => '',
        ]);

        $this->assertSame('203.0.113.10', $mapped['dedicatedip']);
        $this->assertSame("203.0.113.11\n2001:db8::10", $mapped['assignedips']);
    }

    public function test_map_hosting_network_fields_dual_stack_with_single_primary_lists_only_non_primary(): void
    {
        $mapped = SyncService::mapHostingNetworkFields([
            'addresses' => [
                ['id' => 4, 'address' => '198.51.100.10', 'type' => 'ipv4', 'is_primary' => true],
                ['id' => 5, 'address' => '2001:db8::20', 'type' => 'ipv6', 'is_primary' => false],
            ],
            'primary_ipv4' => '198.51.100.10',
            'primary_ipv6' => '',
        ]);

        $this->assertSame('198.51.100.10', $mapped['dedicatedip']);
        $this->assertSame('2001:db8::20', $mapped['assignedips']);
    }

    public function test_map_hosting_network_fields_ipv6_primary_fallback_with_no_extras(): void
    {
        $mapped = SyncService::mapHostingNetworkFields([
            'addresses' => [
                ['id' => 3, 'address' => '2001:db8::10', 'type' => 'ipv6', 'is_primary' => true],
            ],
            'primary_ipv4' => '',
            'primary_ipv6' => '2001:db8::10',
        ]);

        $this->assertSame('2001:db8::10', $mapped['dedicatedip']);
        $this->assertSame('', $mapped['assignedips']);
    }

    public function test_map_hosting_network_fields_no_addresses(): void
    {
        $mapped = SyncService::mapHostingNetworkFields([
            'addresses' => [],
            'primary_ipv4' => '',
            'primary_ipv6' => '',
        ]);

        $this->assertSame('', $mapped['dedicatedip']);
        $this->assertSame('', $mapped['assignedips']);
    }

    public function test_reset_hosting_network_with_updater_sends_empty_network_fields(): void
    {
        $capturedServiceId = 0;
        $capturedFields = null;

        SyncService::resetHostingNetworkWithUpdater(
            55,
            static function (int $serviceId, array $fields) use (&$capturedServiceId, &$capturedFields): void {
                $capturedServiceId = $serviceId;
                $capturedFields = $fields;
            }
        );

        $this->assertSame(55, $capturedServiceId);
        $this->assertSame([
            'dedicatedip' => '',
            'assignedips' => '',
        ], $capturedFields);
    }

    public function test_reset_hosting_network_with_updater_skips_non_positive_service_id(): void
    {
        $called = false;

        SyncService::resetHostingNetworkWithUpdater(
            0,
            static function () use (&$called): void {
                $called = true;
            }
        );

        $this->assertFalse($called);
    }

    public function test_build_specs_for_client_area_prefers_live_values(): void
    {
        $specs = SyncService::buildSpecsForClientArea(
            [
                'cpu' => 2,
                'memory_gb' => 4,
                'disk_gb' => 40,
                'bandwidth_tb' => 1,
                'backup_limit' => 3,
                'snapshot_limit' => 2,
                'os_image_id' => 99,
            ],
            [
                'midgard_live_cpu' => 8,
                'midgard_live_memory' => 17179869184,
                'midgard_live_disk' => 107374182400,
                'midgard_live_bandwidth_limit' => 2199023255552,
                'midgard_live_backup_limit' => 0,
                'midgard_live_snapshot_limit' => 5,
            ]
        );

        $this->assertSame(8, $specs['cpu']);
        $this->assertSame(16.0, $specs['memory_gb']);
        $this->assertSame(100.0, $specs['disk_gb']);
        $this->assertSame(2.0, $specs['bandwidth_tb']);
        $this->assertSame(0, $specs['backup_limit']);
        $this->assertSame(5, $specs['snapshot_limit']);
        $this->assertSame(99, $specs['os_image_id']);
    }

    public function test_build_specs_for_client_area_falls_back_to_config_when_live_values_missing(): void
    {
        $specs = SyncService::buildSpecsForClientArea(
            [
                'cpu' => 4,
                'memory_gb' => 8,
                'disk_gb' => 120,
                'bandwidth_tb' => 3,
                'backup_limit' => 1,
                'snapshot_limit' => 1,
                'os_image_id' => 44,
            ],
            [
                'midgard_live_cpu' => null,
                'midgard_live_memory' => null,
                'midgard_live_disk' => null,
                'midgard_live_bandwidth_limit' => null,
                'midgard_live_backup_limit' => null,
                'midgard_live_snapshot_limit' => null,
            ]
        );

        $this->assertSame(4, $specs['cpu']);
        $this->assertSame(8, $specs['memory_gb']);
        $this->assertSame(120, $specs['disk_gb']);
        $this->assertSame(3, $specs['bandwidth_tb']);
        $this->assertSame(1, $specs['backup_limit']);
        $this->assertSame(1, $specs['snapshot_limit']);
        $this->assertSame(44, $specs['os_image_id']);
    }
}
