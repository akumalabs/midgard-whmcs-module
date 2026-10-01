<?php

declare(strict_types=1);

/**
 * Midgard client-area action proxy (server-side).
 *
 * The browser NEVER sees the panel API token, the console websocket URL,
 * or the VNC password — every panel call happens here, gated by:
 *   1. A WHMCS client session that OWNS the service ($_SESSION['uid']),
 *      plus an X-Midgard-CSRF echo of the per-session token stamped into
 *      the client area template (defence against CSRF on state-changing
 *      actions; the api-style header cannot be forged cross-origin).
 *   2. The service must be an Active/Suspended `midgard` product.
 *   3. Admin-mode connection only — power/rebuild/console are admin
 *      endpoints; reseller tokens get a hard 403 (the client area hides
 *      the action bar in reseller mode as well — this is the second lock).
 *
 * Endpoints (POST unless noted, JSON in/out):
 *   action=osimages   GET-style: eligible OS templates for the rebuild modal
 *   action=progress   install-progress passthrough (modal checklist)
 *   action=power      {action: start|stop|restart|shutdown|reset}
 *   action=rebuild    {os_image_id, password?, name?, hostname?}
 *   action=sso        issues a panel SSO ticket {url} for the console
 *                     (the console itself is served by the PANEL — the
 *                     button opens {panel}/login?sso_ticket=…; the ticket
 *                     value is never logged)
 *
 * Log discipline: logModuleCall receives request SHAPES only; console
 * responses (websocket URL + VNC password) are never logged.
 */

if (! defined('WHMCS')) {
    $whmcsInit = null;
    foreach ([
        dirname(__DIR__, 3) . '/init.inc.php',
        dirname(__DIR__, 3) . '/init.php',
    ] as $candidate) {
        if (is_file($candidate)) {
            $whmcsInit = $candidate;
            break;
        }
    }

    if ($whmcsInit === null) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'WHMCS bootstrap unavailable']);
        exit;
    }

    require $whmcsInit;
}

require_once __DIR__ . '/midgard.php';

header('Content-Type: application/json');

function midgard_ajax_respond(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

try {
    // ── 1. Client session + CSRF ─────────────────────────────────────────
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if ($clientId <= 0) {
        midgard_ajax_respond(403, ['status' => 'error', 'message' => 'Not authenticated.']);
    }

    $csrfHeader = (string) ($_SERVER['HTTP_X_MIDGARD_CSRF'] ?? '');
    $csrfSession = (string) ($_SESSION['midgard_ca_csrf'] ?? '');
    if ($csrfSession === '' || $csrfHeader === '' || ! hash_equals($csrfSession, $csrfHeader)) {
        midgard_ajax_respond(403, ['status' => 'error', 'message' => 'Session expired. Reload the page.']);
    }

    // ── 2. Resolve + authorise the service ───────────────────────────────
    $serviceId = (int) ($_REQUEST['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        midgard_ajax_respond(400, ['status' => 'error', 'message' => 'Missing service.']);
    }

    $row = \WHMCS\Database\Capsule::table('tblhosting')
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->leftJoin('tblservers', 'tblservers.id', '=', 'tblhosting.server')
        ->where('tblhosting.id', $serviceId)
        ->where('tblhosting.userid', $clientId)
        ->whereIn('tblhosting.domainstatus', ['Active', 'Suspended'])
        ->where('tblproducts.servertype', 'midgard')
        ->select([
            'tblhosting.id as serviceid',
            'tblhosting.userid as userid',
            'tblhosting.domain as domain',
            'tblservers.hostname as serverhostname',
            'tblservers.accesshash as serveraccesshash',
            'tblservers.password as serverpassword',
        ])
        ->first();

    if ($row === null) {
        midgard_ajax_respond(403, ['status' => 'error', 'message' => 'Service not found.']);
    }

    $params = [
        'serviceid' => $serviceId,
        'userid' => $clientId,
        'domain' => (string) ($row->domain ?? ''),
        'serverhostname' => (string) ($row->serverhostname ?? ''),
        'serveraccesshash' => (string) ($row->serveraccesshash ?? ''),
        'serverpassword' => (string) ($row->serverpassword ?? ''),
    ];

    // ── 3. Mode guard: admin endpoints are admin-token only ──────────────
    if (\MidgardWhmcs\Config::mode($params) !== \MidgardWhmcs\Config::MODE_ADMIN) {
        midgard_ajax_respond(403, ['status' => 'error', 'message' => 'Actions are unavailable for this connection type.']);
    }

    $store = midgard_store();
    $meta = $store->get($serviceId);
    $serverId = (int) ($meta['midgard_server_id'] ?? 0);
    if ($serverId <= 0) {
        midgard_ajax_respond(404, ['status' => 'error', 'message' => 'Server is not provisioned yet.']);
    }

    $client = midgard_client($params);
    $action = (string) ($_REQUEST['action'] ?? '');

    logModuleCall('midgard', 'clientarea.ajax', [
        'serviceid' => $serviceId,
        'action' => $action,
    ], [
        'requested' => true,
    ], null, []);

    switch ($action) {
        case 'status':
            // LIVE from the panel (not synced meta): the rebuild modal is
            // gone and the badge is the only rebuild feedback the client
            // sees, so it must follow the panel in near-real-time.
            //
            // Task-first: the install task is the authoritative rebuild
            // signal. For a short window after acceptance the VM still
            // reports its OLD power state, so server.status alone would
            // flash RUNNING mid-rebuild.
            $taskStatus = '';
            try {
                // The progress endpoint returns the task object directly
                // ({status, step, progress}) — same envelope the
                // ProvisionStateMapper consumes in syncFromPanel.
                $progress = $client->installProgress($serverId);
                $taskStatus = strtolower(trim((string) ($progress['status'] ?? '')));
            } catch (\Throwable $ignored) {
                $taskStatus = '';
            }

            try {
                $liveData = $client->getServer($serverId);
                $liveData = is_array($liveData['data'] ?? null) ? $liveData['data'] : [];
                $liveStatus = strtolower(trim((string) ($liveData['status'] ?? '')));
            } catch (\Throwable $ignored) {
                $liveStatus = '';
                $liveData = [];
            }

            // Live identity + OS from the SAME payload the poll already
            // fetched: a rebuild renames the server and swaps the OS image
            // — the panel flips os_image_id at REBUILD-JOB START (see
            // RebuildServerJob $updateData), so the new OS name appears
            // exactly when the panel's own UI shows it. All of it reaches
            // the page WITHOUT a manual reload.
            $liveName = trim((string) ($liveData['name'] ?? ''));
            $liveHostname = trim((string) ($liveData['hostname'] ?? ''));
            $liveOsName = '';
            $osImage = $liveData['os_image'] ?? null;
            if (is_array($osImage)) {
                $liveOsName = trim((string) ($osImage['name'] ?? ''));
            }

            $effective = \MidgardWhmcs\SyncService::effectiveRuntimeStatus(
                $taskStatus,
                $liveStatus,
                (string) ($meta['midgard_runtime_status'] ?? ''),
                strtolower(trim((string) ($meta['midgard_provision_state'] ?? '')))
            );

            if ($liveStatus !== '') {
                // Persist the EFFECTIVE status so the next full page render
                // sees the same truth (patchMeta is a targeted single-column
                // write — safe to run alongside syncFromPanel's own upsert).
                try {
                    $store->patchMeta($serviceId, ['midgard_runtime_status' => $effective]);
                } catch (\Throwable $ignored) {
                    // persistence is best-effort; live value already returned
                }
                $meta = $store->get($serviceId);
            }

            midgard_ajax_respond(200, [
                'status' => 'ok',
                'data' => [
                    'status' => $effective,
                    'os_name' => $liveOsName !== '' ? $liveOsName : (string) ($meta['midgard_os_name'] ?? ''),
                    'name' => $liveName !== '' ? $liveName : (string) ($meta['midgard_server_name'] ?? ''),
                    'hostname' => $liveHostname !== '' ? $liveHostname : (string) ($meta['midgard_server_hostname'] ?? ''),
                    'ipv4' => (string) ($meta['midgard_primary_ipv4'] ?? ''),
                    'ipv6' => (string) ($meta['midgard_primary_ipv6'] ?? ''),
                ],
            ]);

        case 'refresh':
            // Explicit client-triggered resync (used by admin-side tooling;
            // client JS now polls 'status' which refreshes inline).
            // includeProgress: TRUE — a refresh mid-rebuild must not write
            // the stale pre-rebuild power status over the task's truth.
            try {
                \MidgardWhmcs\SyncService::syncFromPanel($params, $store, true);
            } catch (\Throwable $ignored) {
                midgard_ajax_respond(502, ['status' => 'error', 'message' => 'Panel sync failed.']);
            }

            midgard_ajax_respond(200, [
                'status' => 'ok',
                'data' => [
                    'status' => (string) ($store->get($serviceId)['midgard_runtime_status'] ?? 'unknown'),
                ],
            ]);

        case 'osimages':
            $images = $client->getOsImages();
            $list = is_array($images['data'] ?? null) ? $images['data'] : [];

            // Server allocation from the live sync (falls back to zeros →
            // only zero-minimum templates pass, which is the safe direction).
            $caps = [
                'cpu' => (int) ($meta['midgard_live_cpu'] ?? 0),
                'memory' => (int) ($meta['midgard_live_memory'] ?? 0),
                'disk' => (int) ($meta['midgard_live_disk'] ?? 0),
            ];

            $eligible = [];
            foreach ($list as $image) {
                if (! is_array($image) || ! (bool) ($image['is_public'] ?? false)) {
                    continue;
                }
                if (! midgard_ajax_meets_minimums($image, $caps)) {
                    continue;
                }
                $eligible[] = [
                    'id' => (int) ($image['id'] ?? 0),
                    'name' => (string) ($image['name'] ?? ''),
                ];
            }

            midgard_ajax_respond(200, ['status' => 'ok', 'data' => $eligible]);

            // no break — exits inside respond

        case 'power':
            $powerAction = (string) ($_POST['action_param'] ?? '');
            if (! in_array($powerAction, ['start', 'stop', 'restart', 'shutdown', 'reset'], true)) {
                midgard_ajax_respond(400, ['status' => 'error', 'message' => 'Unsupported power action.']);
            }

            $client->serverPower($serverId, $powerAction);

            // Best-effort live refresh so the next poll shows the new state.
            try {
                \MidgardWhmcs\SyncService::syncFromPanel($params, $store, false);
            } catch (\Throwable $ignored) {
                // refresh is cosmetic
            }

            midgard_ajax_respond(200, ['status' => 'ok', 'message' => 'Power action accepted.']);

        case 'rebuild':
            $osImageId = (int) ($_POST['os_image_id'] ?? 0);
            if ($osImageId <= 0) {
                midgard_ajax_respond(400, ['status' => 'error', 'message' => 'Select an OS template first.']);
            }

            $payload = ['os_image_id' => $osImageId];

            $password = (string) ($_POST['password'] ?? '');
            if ($password !== '') {
                if (
                    strlen($password) < 8 || strlen($password) > 16
                    || ! preg_match('/[a-z]/', $password)
                    || ! preg_match('/[A-Z]/', $password)
                    || ! preg_match('/\d/', $password)
                    || ! preg_match('/[^A-Za-z0-9]/', $password)
                ) {
                    midgard_ajax_respond(400, ['status' => 'error', 'message' => 'Password must be 8-16 chars with uppercase, lowercase, number, and symbol.']);
                }
                $payload['password'] = $password;
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name !== '') {
                $payload['name'] = mb_substr($name, 0, 255);
            }
            $hostname = trim((string) ($_POST['hostname'] ?? ''));
            if ($hostname !== '') {
                $payload['hostname'] = mb_substr($hostname, 0, 255);
            }

            $client->rebuildServer($serverId, $payload);

            // The install task now owns the server. Refresh meta for
            // os/spec fields WITHOUT letting the still-old server power
            // status overwrite what the task just made true (a rebuild on
            // a running VM reports 'running' for several more seconds).
            try {
                \MidgardWhmcs\SyncService::syncFromPanel($params, $store, true);
                $store->patchMeta($serviceId, ['midgard_runtime_status' => 'rebuilding']);
            } catch (\Throwable $ignored) {
                // refresh is cosmetic; the client badge shows REBUILDING
                // optimistically either way
            }

            logModuleCall('midgard', 'clientarea.ajax.rebuild', [
                'serviceid' => $serviceId,
                'os_image_id' => $osImageId,
            ], [
                'accepted' => true,
            ], null, ['password']);

            midgard_ajax_respond(200, ['status' => 'ok', 'message' => 'Rebuild started.']);

        case 'sso':
            // Console via the panel: issue a short-lived SSO ticket bound to
            // the mapped panel user + server uuid; the button opens
            // {panel}/login?sso_ticket=… and the panel SPA takes it from
            // there. The ticket value itself is never logged.
            $panelUserId = (int) ($meta['midgard_user_id'] ?? 0);
            $serverUuid = trim((string) ($meta['midgard_server_uuid'] ?? ''));
            if ($panelUserId <= 0 || $serverUuid === '') {
                midgard_ajax_respond(409, ['status' => 'error', 'message' => 'Server mapping is incomplete for SSO.']);
            }

            try {
                $ticket = $client->issueSsoTicket([
                    'user_id' => $panelUserId,
                    'server_uuid' => $serverUuid,
                    // Land straight on the panel console page for this server.
                    'redirect' => '/servers/' . $serverUuid . '/console',
                ]);
            } catch (\MidgardWhmcs\MidgardApiException $e) {
                logModuleCall('midgard', 'clientarea.sso', ['serviceid' => $serviceId], [
                    'status_code' => $e->statusCode(),
                ], null, []);
                midgard_ajax_respond(502, ['status' => 'error', 'message' => 'Panel refused the SSO ticket request.']);
            }

            $ticketValue = (string) ($ticket['data']['ticket'] ?? '');
            if ($ticketValue === '') {
                midgard_ajax_respond(502, ['status' => 'error', 'message' => 'Panel did not return an SSO ticket.']);
            }

            // The panel's login route is /auth/login (Login.vue reads the
            // top-level sso_ticket query there; /login does not exist).
            $base = rtrim(\MidgardWhmcs\Config::panelBaseUrl($params), '/');
            midgard_ajax_respond(200, [
                'status' => 'ok',
                'data' => [
                    'url' => $base . '/auth/login?sso_ticket=' . rawurlencode($ticketValue),
                ],
            ]);

        default:
            midgard_ajax_respond(400, ['status' => 'error', 'message' => 'Unknown action.']);
    }
} catch (\MidgardWhmcs\MidgardApiException $e) {
    logModuleCall('midgard', 'clientarea.ajax.error', [
        'action' => (string) ($_REQUEST['action'] ?? ''),
    ], [
        'message' => $e->getMessage(),
        'status' => $e->statusCode(),
    ], null, []);
    midgard_ajax_respond(502, ['status' => 'error', 'message' => 'Panel request failed. Please try again.']);
} catch (\Throwable $e) {
    logModuleCall('midgard', 'clientarea.ajax.error', [
        'action' => (string) ($_REQUEST['action'] ?? ''),
    ], [
        'message' => $e->getMessage(),
    ], null, []);
    midgard_ajax_respond(500, ['status' => 'error', 'message' => 'Unexpected error. Please try again.']);
}

/**
 * Panel-parity minimum-spec filter (mirrors meetsOsImageMinimums):
 * min_cpu cores; min_ram/min_disk GB with legacy MB heuristics.
 *
 * @param array<string, mixed> $image
 * @param array{cpu: int, memory: int, disk: int} $caps
 */
function midgard_ajax_meets_minimums(array $image, array $caps): bool
{
    $asPositive = static function ($value): int {
        $numeric = (int) round((float) $value);
        return $numeric > 0 ? $numeric : 0;
    };

    $minCpu = $asPositive($image['min_cpu'] ?? 0);
    if ($minCpu > 0 && $caps['cpu'] > 0 && $caps['cpu'] < $minCpu) {
        return false;
    }

    $minRam = $asPositive($image['min_ram'] ?? 0);
    if ($minRam > 0 && $caps['memory'] > 0) {
        $minRamBytes = $minRam > 128 ? $minRam * 1024 * 1024 : $minRam * 1024 * 1024 * 1024;
        if ($caps['memory'] < $minRamBytes) {
            return false;
        }
    }

    $minDisk = $asPositive($image['min_disk'] ?? 0);
    if ($minDisk > 0 && $caps['disk'] > 0) {
        $minDiskBytes = $minDisk > 2048 ? $minDisk * 1024 * 1024 : $minDisk * 1024 * 1024 * 1024;
        if ($caps['disk'] < $minDiskBytes) {
            return false;
        }
    }

    return true;
}
