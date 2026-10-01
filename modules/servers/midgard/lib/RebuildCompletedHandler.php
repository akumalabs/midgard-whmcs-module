<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * server.rebuild.completed handler (scaling plan v2 Fase 2).
 *
 * The rebuild chain tail (FinalizeVmStepJob) announces the finished rebuild
 * with the fresh identity payload. This handler mirrors what the next
 * syncFromPanel pass would persist — identity + runtime status + os name —
 * so the client area shows the NEW name/hostname/OS within seconds of the
 * rebuild finishing instead of waiting for the next cron tick.
 *
 * Idempotency: pure state-based writes (same values on retry). No new meta
 * keys — every key written here is already registered in MetadataStore
 * (get/upsert/defaults/ensureMetaColumns) and written by SyncService.
 *
 * Email contract (locked by Master, 2026-10-01): a rebuild NEVER mails
 * credentials, ever. On create the password is system-generated (the email
 * is the only channel that carries it); on rebuild the password — if the
 * operator set one at all — was typed by that same operator, and a blank
 * field keeps the existing one. This handler therefore does NOT touch the
 * email dispatch table in any way; pending create-email rows (if any) stay
 * owned by the cron drain alone.
 */
final class RebuildCompletedHandler
{
    /**
     * @param array<string, mixed> $data envelope "data" object.
     * @return string 'synced' | 'no_service'
     */
    public function handle(array $data): string
    {
        $serverId = (int) ($data['server_id'] ?? 0);
        if ($serverId <= 0) {
            return 'no_service';
        }

        $store = new MetadataStore();

        $serviceIds = Capsule::table('mod_midgard_service_meta')
            ->where('midgard_server_id', (string) $serverId)
            ->pluck('service_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        if ($serviceIds === []) {
            return 'no_service';
        }

        $name = trim((string) ($data['name'] ?? ''));
        $hostname = trim((string) ($data['hostname'] ?? ''));
        $osName = '';
        if (is_array($data['os_image'] ?? null)) {
            $osName = trim((string) ($data['os_image']['name'] ?? ''));
        }

        foreach ($serviceIds as $serviceId) {
            $meta = $store->get($serviceId);
            if (trim((string) ($meta['midgard_server_id'] ?? '')) === '') {
                continue;
            }

            if ($name !== '') {
                $meta['midgard_server_name'] = $name;
            }
            if ($hostname !== '') {
                $meta['midgard_server_hostname'] = $hostname;
            }
            if ($osName !== '') {
                $meta['midgard_os_name'] = $osName;
            }

            // Clear the transitional error surface a failed build may have
            // left; the rebuild succeeded.
            $meta['midgard_last_error'] = '';

            // The rebuild is DONE and the panel reported the VM running.
            // (Effective-badge parity: the next sync would converge here too.)
            $meta['midgard_runtime_status'] = (string) ($data['status'] ?? 'running');

            $store->upsert($serviceId, $meta);
        }

        return 'synced';
    }
}
