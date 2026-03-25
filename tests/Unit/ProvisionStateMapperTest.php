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
}
