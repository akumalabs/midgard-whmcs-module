<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Cron reconciliation engine (scaling plan v2 Fase 3 — zero-config).
 *
 * Replaces the legacy full-loop cron (every Active/Pending/Suspended service
 * synced every tick = 2 HTTP calls per service per tick). New semantics:
 *
 *   1. SUSPECTS (processed first, no time budget beyond the global one):
 *      services whose meta runtime status is transitional (installing,
 *      rebuilding, restoring, creating, deleting) or whose credentials
 *      email dispatch is stuck (queued, unsent). These NEED the panel's
 *      answer to converge.
 *
 *   2. SWEEP (low-priority drain, bounded by the budget): every service in
 *      stable id rotation. The cursor (last hosting id swept) persists in
 *      the install settings table; each tick continues where the previous
 *      one stopped and wraps around. The freshness guarantee is EMERGENT:
 *      data age ≤ one full rotation — no window constant to tune per scale.
 *
 * Budget per tick: stop at 20 seconds elapsed OR 150 panel calls, whichever
 * comes first (the only constant, hardware-adaptive). The sweep IS the
 * reconciliation floor: it always rotates while services exist, so a tick's
 * cost is BOUNDED (≤20 s), never zero — "zero HTTP" holds only while no
 * Midgard services exist. Freshness of the mirror is emergent (≤ one full
 * rotation) with no per-scale tuning anywhere.
 *
 * Overlap lock: a `reconcile_lock` install setting (pid|iso8601) while the
 * engine runs; the next tick skips while the lock is younger than 10
 * minutes and TAKES OVER an older one — a hard-killed cron can never
 * deadlock the rotation (self-healing, no manual intervention).
 */
final class ReconcileEngine
{
    /** Budget: wall-clock seconds per tick. */
    public const TIME_BUDGET_SECONDS = 20.0;

    /** Budget: maximum panel HTTP calls per tick (suspects + sweep). */
    public const CALL_BUDGET = 150;

    /** A lock older than this is considered dead and taken over. */
    public const LOCK_STALE_SECONDS = 600;

    /** Anti-runaway bound on suspects per tick (bug guard, not a tuning knob). */
    public const MAX_SUSPECTS = 250;

    private const LOCK_KEY = 'reconcile_lock';

    private const CURSOR_KEY = 'reconcile_cursor';

    /** Transitional runtime statuses that make a service a suspect. */
    private const TRANSITIONAL = ['installing', 'rebuilding', 'restoring', 'creating', 'deleting'];

    /**
     * Run one reconciliation tick.
     *
     * @return array<string, mixed> stats: skipped, suspects, swept, calls,
     *         budget_exhausted.
     */
    public function run(MetadataStore $store): array
    {
        $stats = [
            'skipped' => false,
            'suspects' => 0,
            'swept' => 0,
            'calls' => 0,
            'budget_exhausted' => false,
        ];

        if (! $this->acquireLock($store)) {
            $stats['skipped'] = true;

            return $stats;
        }

        try {
            $startedAt = microtime(true);

            // ── 1. Suspects: transitional runtime or stuck email dispatch ──
            $suspectIds = $this->suspectServiceIds();

            foreach (array_slice($suspectIds, 0, self::MAX_SUSPECTS) as $serviceId) {
                if ($this->budgetLeft($startedAt, $stats['calls']) === false) {
                    $stats['budget_exhausted'] = true;
                    break;
                }

                if ($this->syncOne($serviceId, $store)) {
                    $stats['calls']++;
                }
                $stats['suspects']++;
            }

            // ── 2. Sweep: stable id rotation, bounded by the budget ──
            while ($this->budgetLeft($startedAt, $stats['calls'])) {
                $cursor = (int) ($store->getInstallSetting(self::CURSOR_KEY) ?? '0');

                $batch = Capsule::table('tblhosting')
                    ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
                    ->where('tblproducts.servertype', 'midgard')
                    ->whereIn('tblhosting.domainstatus', ['Active', 'Pending', 'Suspended'])
                    ->where('tblhosting.id', '>', $cursor)
                    ->orderBy('tblhosting.id')
                    ->limit(25)
                    ->pluck('tblhosting.id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();

                if ($batch === []) {
                    // Rotation complete — start from the beginning next tick.
                    $store->setInstallSetting(self::CURSOR_KEY, '0');
                    break;
                }

                foreach ($batch as $serviceId) {
                    if (! $this->budgetLeft($startedAt, $stats['calls'])) {
                        $stats['budget_exhausted'] = true;
                        break 2;
                    }

                    if ($this->syncOne($serviceId, $store)) {
                        $stats['calls']++;
                    }
                    $stats['swept']++;

                    $store->setInstallSetting(self::CURSOR_KEY, (string) $serviceId);
                }
            }
        } finally {
            $store->setInstallSetting(self::LOCK_KEY, '');
        }

        return $stats;
    }

    /**
     * Service ids needing an URGENT sync: transitional runtime status or a
     * queued, unsent credentials dispatch (local queries only — zero HTTP).
     *
     * @return list<int>
     */
    public function suspectServiceIds(): array
    {
        $transitional = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->leftJoin('mod_midgard_service_meta', 'mod_midgard_service_meta.service_id', '=', 'tblhosting.id')
            ->where('tblproducts.servertype', 'midgard')
            ->whereIn('tblhosting.domainstatus', ['Active', 'Pending', 'Suspended'])
            ->whereIn('mod_midgard_service_meta.midgard_runtime_status', self::TRANSITIONAL)
            ->pluck('tblhosting.id');

        $stuckEmail = Capsule::table('mod_midgard_email_dispatch')
            ->whereNull('sent_at')
            ->whereNotNull('queued_at')
            ->where('queue_attempts', '<', 5)
            ->pluck('service_id');

        return $transitional
            ->merge($stuckEmail)
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Sync one service through the UNCHANGED task-first sync path.
     * Returns true when an HTTP call was (probably) made.
     */
    private function syncOne(int $serviceId, MetadataStore $store): bool
    {
        $params = $this->paramsForService($serviceId);

        if ($params === null) {
            return false; // unconfigured/removed — nothing to call
        }

        try {
            SyncService::syncFromPanel($params, $store);
        } catch (\Throwable $e) {
            if (function_exists('logModuleCall')) {
                logModuleCall('midgard', 'reconcileSync', ['serviceid' => $serviceId], $e->getMessage(), null, []);
            }

            return true; // the attempt itself still counted against the budget
        }

        return true;
    }

    /**
     * Build the module params for one service (same shape the legacy cron
     * assembled before calling syncFromPanel).
     *
     * @return array<string, mixed>|null null when the service/panel row is gone.
     */
    private function paramsForService(int $serviceId): ?array
    {
        try {
            $row = Capsule::table('tblhosting')
                ->leftJoin('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
                ->leftJoin('tblservers', 'tblservers.id', '=', 'tblhosting.server')
                ->where('tblhosting.id', $serviceId)
                ->select([
                    'tblhosting.id as serviceid',
                    'tblhosting.userid as userid',
                    'tblclients.email as email',
                    'tblservers.hostname as serverhostname',
                    'tblservers.accesshash as serveraccesshash',
                    'tblservers.password as serverpassword',
                ])
                ->first();
        } catch (\Throwable $e) {
            return null;
        }

        if ($row === null || trim((string) ($row->serverhostname ?? '')) === '') {
            return null;
        }

        return [
            'serviceid' => (int) ($row->serviceid ?? 0),
            'userid' => (int) ($row->userid ?? 0),
            'serverhostname' => (string) ($row->serverhostname ?? ''),
            'serveraccesshash' => (string) ($row->serveraccesshash ?? ''),
            'serverpassword' => (string) ($row->serverpassword ?? ''),
            'clientsdetails' => [
                'email' => (string) ($row->email ?? ''),
            ],
        ];
    }

    /**
     * Budget check: time AND calls remaining.
     *
     * @param array<string, mixed> $stats
     */
    private function budgetLeft(float $startedAt, int $calls): bool
    {
        return (microtime(true) - $startedAt) < self::TIME_BUDGET_SECONDS
            && $calls < self::CALL_BUDGET;
    }

    /**
     * Take the overlap lock, or refuse while a fresh one is held. A lock
     * older than LOCK_STALE_SECONDS is a dead cron's leftover and is taken
     * over (self-healing).
     */
    private function acquireLock(MetadataStore $store): bool
    {
        $raw = $store->getInstallSetting(self::LOCK_KEY);

        if ($raw !== null && $raw !== '') {
            $startedAt = strtotime((string) substr($raw, (int) strrpos($raw, '|') + 1));

            if ($startedAt !== false && (time() - $startedAt) < self::LOCK_STALE_SECONDS) {
                return false; // fresh lock — another tick is running
            }
        }

        $store->setInstallSetting(self::LOCK_KEY, (string) getmypid().'|'.gmdate('c'));

        return true;
    }
}
