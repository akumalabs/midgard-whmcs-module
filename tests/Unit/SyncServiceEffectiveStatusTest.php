<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use MidgardWhmcs\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * Contract for the client-facing effective runtime status: the install
 * task is authoritative for rebuild sync, the power status is secondary.
 */
final class SyncServiceEffectiveStatusTest extends TestCase
{
    public function test_in_flight_task_forces_rebuilding_even_when_server_still_reports_running(): void
    {
        // The core race: rebuild accepted, VM has not been claimed yet.
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('installing', 'running'));
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('in_progress', 'running'));
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('pending', 'running'));
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('queued', 'stopped'));
    }

    public function test_completed_task_defers_to_power_status(): void
    {
        $this->assertSame('running', SyncService::effectiveRuntimeStatus('completed', 'running'));
        $this->assertSame('stopped', SyncService::effectiveRuntimeStatus('completed', 'stopped'));
    }

    public function test_failed_task_never_claims_running(): void
    {
        $this->assertSame('stopped', SyncService::effectiveRuntimeStatus('failed', 'running'));
        $this->assertSame('stopped', SyncService::effectiveRuntimeStatus('failed', ''));
        $this->assertSame('stopped', SyncService::effectiveRuntimeStatus('failed', 'stopped'));
    }

    public function test_no_task_uses_power_status(): void
    {
        $this->assertSame('running', SyncService::effectiveRuntimeStatus('', 'running'));
        $this->assertSame('suspended', SyncService::effectiveRuntimeStatus('', 'suspended'));
        $this->assertSame('unknown', SyncService::effectiveRuntimeStatus('', ''));
    }

    public function test_normalizes_case_and_whitespace(): void
    {
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('  Installing ', ' RUNNING '));
        $this->assertSame('running', SyncService::effectiveRuntimeStatus('COMPLETED', 'Running'));
    }

    public function test_first_provisioning_keeps_installing_label(): void
    {
        $this->assertSame('installing', SyncService::effectiveRuntimeStatus('installing', 'stopped', 'installing'));
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('installing', 'running', 'running'));
    }

    public function test_brand_new_create_is_installing_not_rebuilding(): void
    {
        // Live fit-and-proper finding 2026-10-01: a fresh create had empty
        // runtime meta, so the previous-status discriminator mislabelled the
        // FIRST install as REBUILDING. midgard_provision_state (MetadataStore
        // default 'installing' at create, 'ready' after first completion) is
        // the real lifecycle signal; previous runtime stays the BC fallback.
        $this->assertSame('installing', SyncService::effectiveRuntimeStatus('installing', 'stopped', '', 'installing'));
        $this->assertSame('installing', SyncService::effectiveRuntimeStatus('installing', 'running', 'unknown', 'installing'));
        // A rebuild of a settled server stays REBUILDING (state never
        // returns to 'installing' once the first build completed).
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('installing', 'running', 'running', 'ready'));
        $this->assertSame('rebuilding', SyncService::effectiveRuntimeStatus('installing', 'stopped', 'stopped', 'ready'));
    }

    public function test_blank_server_status_keeps_previous_settled_state(): void
    {
        $this->assertSame('running', SyncService::effectiveRuntimeStatus('', '', 'running'));
        $this->assertSame('unknown', SyncService::effectiveRuntimeStatus('', '', ''));
    }
}
