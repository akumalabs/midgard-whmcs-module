<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Webhook-side counterpart of the AfterCronJob credentials flush worker
 * (hooks.php). When the panel pushes server.build.completed, this handler:
 *
 *   1. Finds every WHMCS service whose meta maps to the panel server id
 *      (a shared panel server can back several services).
 *   2. Skips services whose credentials dispatch was already sent
 *      (idempotency — retried webhook deliveries never double-mail).
 *   3. Runs the SAME live gate as the cron worker
 *      (SyncService::credentialsEmailGate — server MUST be 'ready').
 *   4. Sends via the SAME path (PasswordMailer::sendOneTime, async shape,
 *      re-attaching the sealed password from metadata).
 *
 * The AfterCronJob flush worker remains fully intact as the safety net.
 */
final class CallbackHandler
{
    /**
     * Handle a verified build-completed envelope.
     *
     * @param array<string, mixed> $data envelope "data" object.
     * @return string 'sent' | 'already_sent' | 'not_ready' | 'no_service'
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

        $statuses = [];
        foreach ($serviceIds as $serviceId) {
            $statuses[] = $this->handleService($serviceId, $store);
        }

        // Aggregate: a fresh send wins; otherwise, if any service still owes
        // its email, report not_ready so the panel retries (useful);
        // already_sent only when every mapped service is done.
        if (in_array('sent', $statuses, true)) {
            return 'sent';
        }
        if (in_array('not_ready', $statuses, true)) {
            return 'not_ready';
        }
        if (in_array('already_sent', $statuses, true)) {
            return 'already_sent';
        }

        return 'no_service';
    }

    private function handleService(int $serviceId, MetadataStore $store): string
    {
        if ($serviceId <= 0) {
            return 'no_service';
        }

        $meta = $store->get($serviceId);
        if (trim((string) ($meta['midgard_server_id'] ?? '')) === '') {
            return 'no_service';
        }

        // Idempotency: the credentials dispatch row is the single source of
        // truth. A queued, unsent dispatch is deliverable (sendOneTime
        // finalizes it; a retried delivery then finds none → already_sent).
        // A SENT dispatch row means the email already went out — retries
        // never double-mail. Nothing at all means provisioning has not
        // reached the queue step yet → not_ready (cron safety net still owns
        // that case).
        $dispatch = $store->pendingDispatchForService($serviceId);

        if ($dispatch === null) {
            $hasSentRow = Capsule::table('mod_midgard_email_dispatch')
                ->where('service_id', $serviceId)
                ->whereNotNull('sent_at')
                ->exists();

            return $hasSentRow ? 'already_sent' : 'not_ready';
        }

        $serverUuid = trim((string) ($dispatch['server_uuid'] ?? ''));
        if ($serverUuid === '') {
            $serverUuid = trim((string) ($meta['midgard_server_uuid'] ?? ''));
        }

        // Live gate: identical to the cron flush worker — only a server the
        // panel reports as provisioned (ready) may mail credentials.
        $gateParams = self::paramsForService($serviceId);
        if ($gateParams === null) {
            return 'not_ready';
        }

        try {
            $gate = SyncService::credentialsEmailGate($gateParams, $store);
        } catch (\Throwable $e) {
            $gate = ['send' => false, 'reason' => 'gate_error: ' . $e->getMessage()];
        }

        if (! ($gate['send'] ?? false)) {
            self::log('callback.notReady', $serviceId, (string) ($gate['reason'] ?? 'unknown'));
            return 'not_ready';
        }

        try {
            $dispatchHash = (string) ($dispatch['dispatch_hash'] ?? '');
            $store->incrementPasswordDispatchAttempts($dispatchHash);
            PasswordMailer::sendOneTime($gateParams, $store, $serverUuid, null, $dispatchHash);
        } catch (\Throwable $e) {
            $store->recordPasswordDispatchError((string) ($dispatch['dispatch_hash'] ?? ''), $e->getMessage());
            self::log('callback.sendFailed', $serviceId, $e->getMessage());
            return 'not_ready';
        }

        self::log('callback.sent', $serviceId, '');
        return 'sent';
    }

    /**
     * Build module params (tblhosting + tblservers) for a service — the same
     * shape the cron worker assembles before calling the gate / mailer.
     *
     * @return array<string, mixed>|null null when the service/panel row is gone.
     */
    private static function paramsForService(int $serviceId): ?array
    {
        try {
            $row = Capsule::table('tblhosting')
                ->leftJoin('tblservers', 'tblservers.id', '=', 'tblhosting.server')
                ->where('tblhosting.id', $serviceId)
                ->select([
                    'tblhosting.userid as userid',
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
            'serviceid' => $serviceId,
            'userid' => (int) ($row->userid ?? 0),
            'serverhostname' => (string) ($row->serverhostname ?? ''),
            'serveraccesshash' => (string) ($row->serveraccesshash ?? ''),
            'serverpassword' => (string) ($row->serverpassword ?? ''),
        ];
    }

    private static function log(string $action, int $serviceId, string $message): void
    {
        if (! function_exists('logModuleCall')) {
            return;
        }

        logModuleCall(
            'midgard',
            $action,
            ['serviceid' => $serviceId],
            $message,
            null,
            []
        );
    }
}
