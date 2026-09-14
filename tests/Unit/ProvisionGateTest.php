<?php

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\ProvisionGate;
use PHPUnit\Framework\TestCase;

final class ProvisionGateTest extends TestCase
{
    public function testReadyStateAllowsSend(): void
    {
        $this->assertSame(['send' => true, 'reason' => ''], ProvisionGate::evaluate('ready'));
        $this->assertSame(['send' => true, 'reason' => ''], ProvisionGate::evaluate(' READY '));
    }

    public function testInstallingStateDefers(): void
    {
        $gate = ProvisionGate::evaluate('installing');
        $this->assertFalse($gate['send']);
        $this->assertSame('provision_state_installing', $gate['reason']);
    }

    public function testFailedStateNeverSends(): void
    {
        $gate = ProvisionGate::evaluate('failed');
        $this->assertFalse($gate['send']);
        $this->assertSame('provision_failed', $gate['reason']);
    }

    public function testUnknownOrEmptyStateFailsClosed(): void
    {
        $gate = ProvisionGate::evaluate('');
        $this->assertFalse($gate['send']);
        $this->assertSame('provision_state_unknown', $gate['reason']);

        $gate = ProvisionGate::evaluate('some_future_state');
        $this->assertFalse($gate['send']);
        $this->assertSame('provision_state_some_future_state', $gate['reason']);
    }
}
