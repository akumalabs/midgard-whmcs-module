<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\ProvisionStateMapper;
use PHPUnit\Framework\TestCase;

final class ProvisionStateMapperTest extends TestCase
{
    public function test_maps_failed_progress_with_error_detail(): void
    {
        $mapped = ProvisionStateMapper::fromInstallProgress([
            'status' => 'failed',
            'step' => 'cloning',
            'error' => 'Image clone failed',
        ]);

        $this->assertSame('failed', $mapped['state']);
        $this->assertSame('Image clone failed', $mapped['error']);
    }

    public function test_maps_completed_progress_to_ready(): void
    {
        $mapped = ProvisionStateMapper::fromInstallProgress([
            'status' => 'completed',
            'step' => 'completed',
        ]);

        $this->assertSame('ready', $mapped['state']);
        $this->assertSame('', $mapped['error']);
    }

    public function test_maps_running_progress_to_installing(): void
    {
        $mapped = ProvisionStateMapper::fromInstallProgress([
            'status' => 'running',
            'step' => 'networking',
        ]);

        $this->assertSame('installing', $mapped['state']);
    }

    public function test_maps_panel_status_installed_to_ready(): void
    {
        // The panel's ServerStatus enum after a successful build is
        // 'installed' (install lifecycle) — NOT a power state.
        $mapped = ProvisionStateMapper::fromServerStatus(['status' => 'installed']);
        $this->assertSame('ready', $mapped['state']);
        $this->assertSame('', $mapped['error']);
    }

    public function test_maps_panel_power_statuses_to_ready(): void
    {
        foreach (['running', 'stopped'] as $status) {
            $mapped = ProvisionStateMapper::fromServerStatus(['status' => $status]);
            $this->assertSame('ready', $mapped['state'], "status {$status} must be ready");
        }
    }

    public function test_maps_panel_status_failed_to_failed(): void
    {
        $mapped = ProvisionStateMapper::fromServerStatus([
            'status' => 'install_failed',
            'install_error_detail' => 'Template not found',
        ]);
        $this->assertSame('failed', $mapped['state']);
        $this->assertSame('Template not found', $mapped['error']);
    }

    public function test_maps_panel_transient_statuses_to_defer(): void
    {
        foreach (['installing', 'suspended', 'deleting', 'migrating', ''] as $status) {
            $mapped = ProvisionStateMapper::fromServerStatus(['status' => $status]);
            $this->assertSame('installing', $mapped['state'], "status {$status} must defer");
            $this->assertSame('', $mapped['error']);
        }
    }
}
