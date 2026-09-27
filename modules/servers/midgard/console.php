<?php

declare(strict_types=1);

/**
 * Midgard client-area console (direct, no panel hop).
 *
 * Serves a self-contained dark noVNC shell that connects the browser
 * straight to the panel's VNC websocket proxy (wss://<panel>/vnc?token=…).
 *
 * Security model (mirrors ajax.php):
 *   - WHMCS client session owning the service, PLUS the X-Midgard-CSRF
 *     header echo (api-style header cannot be forged cross-origin);
 *   - service must be an Active/Suspended `midgard` product;
 *   - admin-mode connection only (console endpoint is admin-side);
 *   - the console session URL and VNC password exist ONLY inside this
 *     page's fetch — they are never logged and never rendered into HTML.
 *
 * Static asset serving (assets/novnc/rfb.bundle.js) is realpath-guarded;
 * noVNC is MIT-licensed (LICENSE.novnc.txt sits next to the bundle).
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

function midgard_console_guard(): array
{
    $clientId = (int) ($_SESSION['uid'] ?? 0);
    if ($clientId <= 0) {
        http_response_code(403);
        exit('Not authenticated.');
    }

    // NOTE: no CSRF header here BY DESIGN — this page opens via a plain
    // window.open() navigation, which cannot carry custom headers. Issuing
    // a console session is non-destructive (worst case under a forged
    // top-level navigation with a Lax-same-site session cookie: a console
    // tab opens). The DESTRUCTIVE paths (power/rebuild) all go through
    // ajax.php, which enforces the X-Midgard-CSRF double-submit check.

    // Static asset fetches bypass the per-service checks but keep the
    // session gate above.
    if (($_GET['asset'] ?? '') === 'rfb') {
        return ['serviceId' => 0, 'params' => []];
    }

    $serviceId = (int) ($_GET['serviceid'] ?? 0);
    if ($serviceId <= 0) {
        http_response_code(400);
        exit('Missing service.');
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
        http_response_code(403);
        exit('Service not found.');
    }

    $params = [
        'serviceid' => $serviceId,
        'userid' => $clientId,
        'domain' => (string) ($row->domain ?? ''),
        'serverhostname' => (string) ($row->serverhostname ?? ''),
        'serveraccesshash' => (string) ($row->serveraccesshash ?? ''),
        'serverpassword' => (string) ($row->serverpassword ?? ''),
    ];

    if (\MidgardWhmcs\Config::mode($params) !== \MidgardWhmcs\Config::MODE_ADMIN) {
        http_response_code(403);
        exit('Console is unavailable for this connection type.');
    }

    return ['serviceId' => $serviceId, 'params' => $params];
}

$guard = midgard_console_guard();

// ── Static noVNC bundle ─────────────────────────────────────────────────
if (($guard['serviceId'] ?? 0) === 0) {
    $assetBase = realpath(__DIR__ . '/assets/novnc');
    $bundlePath = $assetBase === false ? false : realpath($assetBase . '/rfb.bundle.js');
    if ($bundlePath === false || ! str_starts_with($bundlePath, $assetBase . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('Console assets missing.');
    }

    header('Content-Type: application/javascript; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($bundlePath);
    exit;
}

$serviceId = (int) $guard['serviceId'];
$params = $guard['params'];

try {
    $store = midgard_store();
    $meta = $store->get($serviceId);
    $panelServerId = (int) ($meta['midgard_server_id'] ?? 0);
    if ($panelServerId <= 0) {
        http_response_code(404);
        exit('Server is not provisioned yet.');
    }

    if (\MidgardWhmcs\Config::mode($params) !== \MidgardWhmcs\Config::MODE_ADMIN) {
        http_response_code(403);
        exit('Console is unavailable for this connection type.');
    }

    $client = midgard_client($params);
    $console = $client->serverConsole($panelServerId);
    $data = is_array($console['data'] ?? null) ? $console['data'] : [];
    $wsUrl = (string) ($data['url'] ?? '');
    $vncPassword = (string) ($data['password'] ?? '');

    if ($wsUrl === '') {
        http_response_code(502);
        exit('Panel did not return a console session.');
    }

    // NEVER log $wsUrl / $vncPassword (VNC ticket + password material).
    $serverName = trim((string) ($meta['midgard_server_name'] ?? ''));
    if ($serverName === '') {
        $serverName = (string) ($params['domain'] ?? ('Service #' . $serviceId));
    }
} catch (\Throwable $e) {
    logModuleCall('midgard', 'clientarea.console.error', [
        'serviceid' => $serviceId,
    ], [
        'message' => $e->getMessage(),
    ], null, []);
    http_response_code(502);
    exit('Console session could not be created.');
}

$ajaxUrl = 'clientarea.php?action=productinfo&id=' . $serviceId;
$assetUrl = 'modules/servers/midgard/console.php?asset=rfb&serviceid=' . $serviceId;
$wsUrlJson = json_encode($wsUrl, JSON_UNESCAPED_SLASHES) ?: '""';
$passwordJson = json_encode($vncPassword, JSON_UNESCAPED_SLASHES) ?: '""';
$nameJson = json_encode($serverName, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Console — <?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?></title>
<style>
    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
        background: #09090b;
        color: #d4d4d8;
        font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    #bar {
        align-items: center;
        background: #18181b;
        border-bottom: 1px solid #2a2a3d;
        display: flex;
        gap: 12px;
        padding: 10px 16px;
    }
    #bar .title { font-size: 13px; font-weight: 600; }
    #bar .status { color: #a1a1aa; font-size: 12px; }
    #bar .spacer { flex: 1; }
    #bar button {
        background: #2a2a3d;
        border: 1px solid #3f3f5a;
        border-radius: 6px;
        color: #d4d4d8;
        cursor: pointer;
        font-size: 12px;
        padding: 6px 12px;
    }
    #bar button:hover { background: #35354d; }
    #screen { flex: 1; min-height: 0; }
    #screen > div { height: 100%; width: 100%; }
    #overlay {
        align-items: center;
        background: #09090b;
        display: flex;
        flex: 1;
        flex-direction: column;
        gap: 12px;
        justify-content: center;
        position: absolute;
        z-index: 10;
    }
    #overlay.error { color: #ef4444; }
    .spinner {
        animation: midgard-spin 0.8s linear infinite;
        border: 3px solid #2a2a3d;
        border-radius: 50%;
        border-top-color: #6366f1;
        height: 28px;
        width: 28px;
    }
    @keyframes midgard-spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div id="bar">
    <span class="title"><?= htmlspecialchars($serverName, ENT_QUOTES, 'UTF-8') ?> — Console</span>
    <span class="status" id="status">Connecting…</span>
    <span class="spacer"></span>
    <button type="button" id="btn-reconnect">Reconnect</button>
    <button type="button" id="btn-close">Close</button>
</div>
<div id="screen">
    <div id="overlay">
        <div class="spinner"></div>
        <div id="overlay-text">Requesting console session…</div>
    </div>
</div>
<script>
(function () {
    "use strict";

    var WS_URL = <?= $wsUrlJson ?>;
    var VNC_PASSWORD = <?= $passwordJson ?>;
    var SERVER_NAME = <?= $nameJson ?>;

    var screenEl = document.getElementById("screen");
    var overlay = document.getElementById("overlay");
    var overlayText = document.getElementById("overlay-text");
    var statusEl = document.getElementById("status");
    var rfb = null;

    function positionOverlay() {
        var rect = screenEl.getBoundingClientRect();
        overlay.style.left = rect.left + "px";
        overlay.style.top = rect.top + "px";
        overlay.style.width = rect.width + "px";
        overlay.style.height = rect.height + "px";
    }

    function showOverlay(text, isError) {
        positionOverlay();
        overlayText.textContent = text;
        overlay.classList.toggle("error", Boolean(isError));
        overlay.querySelector(".spinner").style.display = isError ? "none" : "block";
        overlay.style.display = "flex";
    }

    function hideOverlay() {
        overlay.style.display = "none";
    }

    function setStatus(text) {
        statusEl.textContent = text;
    }

    function connect() {
        showOverlay("Connecting to console…", false);
        setStatus("Connecting…");

        import("<?= $assetUrl ?>&t=" + Date.now()).then(function (mod) {
            var RFB = mod.default;
            if (rfb) {
                try { rfb.disconnect(); } catch (e) { /* already gone */ }
                rfb = null;
            }
            while (screenEl.firstChild) {
                screenEl.removeChild(screenEl.firstChild);
            }

            rfb = new RFB(screenEl, WS_URL, {
                credentials: { password: VNC_PASSWORD }
            });
            rfb.qualityLevel = 6;
            rfb.compressionLevel = 2;
            rfb.scaleViewport = true;
            rfb.background = "#000000";
            rfb.showDotCursor = true;

            rfb.addEventListener("connect", function () {
                hideOverlay();
                setStatus("Connected — " + SERVER_NAME);
            });
            rfb.addEventListener("disconnect", function (ev) {
                var detail = ev && ev.detail && ev.detail.reason ? String(ev.detail.reason) : "";
                showOverlay("Console disconnected." + (detail ? " (" + detail + ")" : ""), true);
                setStatus("Disconnected");
            });
        }).catch(function (err) {
            showOverlay("Failed to load console engine.", true);
            setStatus("Error");
        });
    }

    document.getElementById("btn-reconnect").addEventListener("click", connect);
    document.getElementById("btn-close").addEventListener("click", function () {
        window.close();
        window.location.href = <?= json_encode($ajaxUrl, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    });
    window.addEventListener("resize", positionOverlay);

    connect();
})();
</script>
</body>
</html>
