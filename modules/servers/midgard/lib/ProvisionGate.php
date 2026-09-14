<?php

declare(strict_types=1);

namespace MidgardWhmcs;

/**
 * Credentials-email gate: the welcome email may only be sent once the
 * server actually finished provisioning (state = ready). This mirrors
 * VirtFusion's behavior of mailing credentials after the build
 * completes — never while the VM is still installing (or after it
 * failed).
 *
 * The gate deliberately NEVER approves on an unknown state: the async
 * fast path seeds no provision state at create time, so the safe
 * default is to defer until a sync has observed 'ready'.
 */
final class ProvisionGate
{
    /**
     * @param string $state normalized provision state ('' | installing | ready | failed | ...)
     * @return array{send: bool, reason: string}
     */
    public static function evaluate(string $state): array
    {
        $state = strtolower(trim($state));

        if ($state === 'ready') {
            return ['send' => true, 'reason' => ''];
        }

        if ($state === 'failed') {
            return ['send' => false, 'reason' => 'provision_failed'];
        }

        if ($state === '' || $state === 'installing') {
            return ['send' => false, 'reason' => 'provision_state_' . ($state === '' ? 'unknown' : $state)];
        }

        // Unknown future states defer too — fail closed, never mail early.
        return ['send' => false, 'reason' => 'provision_state_' . $state];
    }
}
