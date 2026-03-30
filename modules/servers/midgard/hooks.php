<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\EmailTemplateGuard;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\SyncService;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/midgard.php';

if (! function_exists('add_hook')) {
    return;
}

/**
 * @param array<string, mixed> $vars
 */
function midgard_hookResolveProductDetailsServiceId(array $vars): int
{
    $candidates = [
        (int) ($vars['serviceid'] ?? 0),
        (int) ($vars['id'] ?? 0),
        (int) (($vars['service']['id'] ?? 0)),
        (int) (($vars['clientsservice']['id'] ?? 0)),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate > 0) {
            return $candidate;
        }
    }

    return 0;
}

/**
 * @return array<string, mixed>|null
 */
function midgard_hookLoadServiceContext(int $serviceId): ?array
{
    if ($serviceId <= 0) {
        return null;
    }

    $row = Capsule::table('tblhosting')
        ->leftJoin('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->leftJoin('tblservers', 'tblservers.id', '=', 'tblhosting.server')
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.id', $serviceId)
        ->select([
            'tblhosting.id as serviceid',
            'tblhosting.userid as userid',
            'tblhosting.domainstatus as domainstatus',
            'tblhosting.servertype as servertype',
            'tblclients.email as email',
            'tblclients.firstname as firstname',
            'tblclients.lastname as lastname',
            'tblservers.hostname as serverhostname',
            'tblservers.accesshash as serveraccesshash',
            'tblservers.password as serverpassword',
            'tblproducts.configoption1 as configoption1',
            'tblproducts.configoption2 as configoption2',
            'tblproducts.configoption3 as configoption3',
            'tblproducts.configoption4 as configoption4',
            'tblproducts.configoption5 as configoption5',
            'tblproducts.configoption6 as configoption6',
            'tblproducts.configoption7 as configoption7',
            'tblproducts.configoption8 as configoption8',
            'tblproducts.configoption9 as configoption9',
            'tblproducts.configoption10 as configoption10',
            'tblproducts.configoption11 as configoption11',
        ])
        ->first();

    if ($row === null) {
        return null;
    }

    if (strtolower(trim((string) ($row->servertype ?? ''))) !== 'midgard') {
        return null;
    }

    $configOptions = [
        'location_id' => (string) ($row->configoption1 ?? ''),
        'os_image_id' => (string) ($row->configoption2 ?? ''),
        'cpu' => (string) ($row->configoption3 ?? ''),
        'memory_gb' => (string) ($row->configoption4 ?? ''),
        'disk_gb' => (string) ($row->configoption5 ?? ''),
        'bandwidth_tb' => (string) ($row->configoption6 ?? ''),
        'backup_limit' => (string) ($row->configoption7 ?? ''),
        'snapshot_limit' => (string) ($row->configoption8 ?? ''),
        'default_ipv4' => (string) ($row->configoption9 ?? ''),
        'default_ipv6' => (string) ($row->configoption10 ?? ''),
        'welcome_email_template' => (string) ($row->configoption11 ?? ''),
    ];

    return [
        'serviceid' => (int) ($row->serviceid ?? 0),
        'userid' => (int) ($row->userid ?? 0),
        'status' => (string) ($row->domainstatus ?? ''),
        'serverhostname' => (string) ($row->serverhostname ?? ''),
        'serveraccesshash' => (string) ($row->serveraccesshash ?? ''),
        'serverpassword' => (string) ($row->serverpassword ?? ''),
        'configoptions' => $configOptions,
        'clientsdetails' => [
            'email' => (string) ($row->email ?? ''),
            'firstname' => (string) ($row->firstname ?? ''),
            'lastname' => (string) ($row->lastname ?? ''),
        ],
    ];
}

function midgard_hookEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/**
 * @param array<string, mixed> $templateVariables
 */
function midgard_hookRenderClientAreaFallback(array $templateVariables): string
{
    $stateLabel = (string) ($templateVariables['midgardProvisionStateLabel'] ?? 'Installing');
    $stateClass = (string) ($templateVariables['midgardProvisionStateClass'] ?? 'midgard-state-installing');
    $allowedStateClasses = ['midgard-state-ready', 'midgard-state-failed', 'midgard-state-installing'];
    if (! in_array($stateClass, $allowedStateClasses, true)) {
        $stateClass = 'midgard-state-installing';
    }

    $primaryIpv4 = (string) ($templateVariables['midgardPrimaryIpv4'] ?? '');
    $primaryIpv6 = (string) ($templateVariables['midgardPrimaryIpv6'] ?? '');
    $provisionError = (string) ($templateVariables['midgardProvisionError'] ?? '');
    $ipv4Missing = (bool) ($templateVariables['midgardIpv4Missing'] ?? false);
    $ipv4Warning = (string) ($templateVariables['midgardIpv4Warning'] ?? '');
    $ssoUrl = trim((string) ($templateVariables['midgardSsoUrl'] ?? ''));

    $addresses = [];
    if (is_array($templateVariables['midgardAddresses'] ?? null)) {
        $addresses = $templateVariables['midgardAddresses'];
    }

    $specs = [
        'cpu' => 0,
        'memory_gb' => 0,
        'disk_gb' => 0,
        'bandwidth_tb' => 0,
        'backup_limit' => 0,
        'snapshot_limit' => 0,
        'os_image_id' => 0,
    ];
    if (is_array($templateVariables['midgardSpecs'] ?? null)) {
        $specs = array_merge($specs, $templateVariables['midgardSpecs']);
    }

    $networkRows = '';
    foreach ($addresses as $addressRow) {
        if (! is_array($addressRow)) {
            continue;
        }

        $type = strtoupper(trim((string) ($addressRow['type'] ?? '')));
        $address = trim((string) ($addressRow['address'] ?? ''));
        if ($type === '' || $address === '') {
            continue;
        }

        $primaryLabel = ((bool) ($addressRow['is_primary'] ?? false)) ? ' <strong>(Primary)</strong>' : '';
        $networkRows .= '<li><span>' . midgard_hookEsc($type) . '</span><span>'
            . midgard_hookEsc($address) . $primaryLabel . '</span></li>';
    }

    if ($networkRows === '') {
        $networkRows = '<p class="midgard-muted">No IP addresses are currently assigned to this server.</p>';
    } else {
        $networkRows = '<ul class="midgard-list">' . $networkRows . '</ul>';
    }

    $warningHtml = '';
    if ($ipv4Missing && trim($ipv4Warning) !== '') {
        $warningHtml = '<p class="midgard-warning" style="margin-top:10px;">' . midgard_hookEsc($ipv4Warning) . '</p>';
    }

    $errorHtml = '';
    if (trim($provisionError) !== '') {
        $errorHtml = '<p class="midgard-error">' . midgard_hookEsc($provisionError) . '</p>';
    }

    $ssoHtml = '';
    if ($ssoUrl !== '') {
        $ssoHtml = '<a href="' . midgard_hookEsc($ssoUrl)
            . '" target="_blank" rel="noopener" class="midgard-sso-btn">Open in Panel (SSO)</a>';
    } else {
        $ssoHtml = '<p class="midgard-muted">SSO ticket is currently unavailable.</p>';
    }

    $primaryIpv4Badge = midgard_hookEsc($primaryIpv4 !== '' ? $primaryIpv4 : 'Not assigned');
    $primaryIpv6Badge = midgard_hookEsc($primaryIpv6 !== '' ? $primaryIpv6 : 'Not assigned');
    $cpuText = midgard_hookEsc((string) $specs['cpu']);
    $memoryText = midgard_hookEsc((string) $specs['memory_gb']);
    $diskText = midgard_hookEsc((string) $specs['disk_gb']);
    $bandwidthText = midgard_hookEsc((string) $specs['bandwidth_tb']);
    $backupLimitText = midgard_hookEsc((string) $specs['backup_limit']);
    $snapshotLimitText = midgard_hookEsc((string) $specs['snapshot_limit']);
    $osImageText = midgard_hookEsc((string) $specs['os_image_id']);

    return <<<HTML
<div class="midgard-hook-fallback-root">
    <style>
    .midgard-client-area { display: grid; gap: 16px; margin-top: 16px; }
    .midgard-grid { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
    .midgard-card { background: linear-gradient(180deg, #0f172a 0%, #111827 100%); color: #e5e7eb; border: 1px solid #334155; border-radius: 14px; padding: 18px; box-shadow: 0 10px 30px rgba(2, 6, 23, 0.35); }
    .midgard-card-title { margin: 0 0 12px; font-size: 14px; font-weight: 700; letter-spacing: 0.03em; text-transform: uppercase; color: #93c5fd; }
    .midgard-state { display: inline-flex; align-items: center; padding: 6px 10px; border-radius: 999px; font-size: 13px; font-weight: 700; }
    .midgard-state-ready { background: #052e1b; color: #86efac; border: 1px solid #166534; }
    .midgard-state-failed { background: #450a0a; color: #fca5a5; border: 1px solid #7f1d1d; }
    .midgard-state-installing { background: #172554; color: #bfdbfe; border: 1px solid #1d4ed8; }
    .midgard-warning { margin: 0; background: #3f2a00; border: 1px solid #854d0e; color: #fde68a; border-radius: 10px; padding: 10px 12px; font-size: 13px; }
    .midgard-error { margin: 10px 0 0; background: #3f1212; border: 1px solid #7f1d1d; color: #fecaca; border-radius: 10px; padding: 10px 12px; font-size: 13px; }
    .midgard-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .midgard-badge { display: inline-flex; align-items: center; border-radius: 999px; border: 1px solid #334155; background: #111827; color: #cbd5e1; padding: 4px 10px; font-size: 12px; font-weight: 600; }
    .midgard-badge-ipv4 { border-color: #1d4ed8; color: #bfdbfe; }
    .midgard-badge-ipv6 { border-color: #6d28d9; color: #ddd6fe; }
    .midgard-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
    .midgard-list li { display: flex; justify-content: space-between; gap: 12px; border-bottom: 1px dashed #334155; padding-bottom: 7px; font-size: 13px; }
    .midgard-list li:last-child { border-bottom: 0; padding-bottom: 0; }
    .midgard-muted { margin: 0; color: #94a3b8; font-size: 13px; }
    .midgard-sso-btn { display: inline-block; background: linear-gradient(90deg, #2563eb 0%, #7c3aed 100%); color: #f8fafc; border: 0; border-radius: 10px; padding: 10px 14px; text-decoration: none; font-weight: 700; }
    </style>

    <div class="midgard-client-area">
        <section class="midgard-card">
            <h3 class="midgard-card-title">Provisioning Status</h3>
            <span class="midgard-state {$stateClass}">{$stateLabel}</span>
            {$warningHtml}
            {$errorHtml}
        </section>

        <div class="midgard-grid">
            <section class="midgard-card">
                <h3 class="midgard-card-title">Network</h3>
                <div class="midgard-badges">
                    <span class="midgard-badge midgard-badge-ipv4">Primary IPv4: {$primaryIpv4Badge}</span>
                    <span class="midgard-badge midgard-badge-ipv6">Primary IPv6: {$primaryIpv6Badge}</span>
                </div>
                {$networkRows}
            </section>

            <section class="midgard-card">
                <h3 class="midgard-card-title">Server Specs</h3>
                <ul class="midgard-list">
                    <li><span>CPU</span><span>{$cpuText} vCPU</span></li>
                    <li><span>RAM</span><span>{$memoryText} GB</span></li>
                    <li><span>Disk</span><span>{$diskText} GB</span></li>
                    <li><span>Bandwidth</span><span>{$bandwidthText} TB</span></li>
                    <li><span>Backup Limit</span><span>{$backupLimitText}</span></li>
                    <li><span>Snapshot Limit</span><span>{$snapshotLimitText}</span></li>
                    <li><span>OS Image ID</span><span>{$osImageText}</span></li>
                </ul>
            </section>
        </div>

        <section class="midgard-card">
            <h3 class="midgard-card-title">Panel Access</h3>
            {$ssoHtml}
        </section>
    </div>
    <script>
    (function () {
        var script = document.currentScript;
        if (!script || !script.parentElement) {
            return;
        }
        var root = script.parentElement;
        var blocks = document.querySelectorAll('.midgard-client-area');
        if (blocks.length > 1) {
            root.style.display = 'none';
        }
    })();
    </script>
</div>
HTML;
}

/**
 * @param array<string, mixed> $vars
 */
function midgard_hookResolveMidgardServiceIdForEmail(array $vars): int
{
    $serviceIdCandidates = [
        (int) ($vars['relid'] ?? 0),
        (int) ($vars['id'] ?? 0),
        (int) ($vars['serviceid'] ?? 0),
    ];

    foreach ($serviceIdCandidates as $serviceId) {
        if ($serviceId <= 0) {
            continue;
        }

        $servertype = Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('servertype');

        if (strtolower(trim((string) ($servertype ?? ''))) === 'midgard') {
            return $serviceId;
        }
    }

    return 0;
}

function midgard_hookResolveConfiguredTemplateForService(int $serviceId): string
{
    if ($serviceId <= 0) {
        return 'Midgard Provisioning Credentials';
    }

    $templateName = Capsule::table('tblhosting')
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.id', $serviceId)
        ->value('tblproducts.configoption11');

    $resolved = trim((string) ($templateName ?? ''));
    return $resolved !== '' ? $resolved : 'Midgard Provisioning Credentials';
}

add_hook('AfterCronJob', 1, function (): void {
    $store = new MetadataStore();

    $services = Capsule::table('tblhosting')
        ->leftJoin('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->leftJoin('tblservers', 'tblservers.id', '=', 'tblhosting.server')
        ->where('tblhosting.servertype', 'midgard')
        ->whereIn('tblhosting.domainstatus', ['Active', 'Pending', 'Suspended'])
        ->select([
            'tblhosting.id as serviceid',
            'tblhosting.userid as userid',
            'tblclients.email as email',
            'tblservers.hostname as serverhostname',
            'tblservers.accesshash as serveraccesshash',
            'tblservers.password as serverpassword',
        ])
        ->get();

    foreach ($services as $service) {
        $params = [
            'serviceid' => (int) ($service->serviceid ?? 0),
            'userid' => (int) ($service->userid ?? 0),
            'serverhostname' => (string) ($service->serverhostname ?? ''),
            'serveraccesshash' => (string) ($service->serveraccesshash ?? ''),
            'serverpassword' => (string) ($service->serverpassword ?? ''),
            'clientsdetails' => [
                'email' => (string) ($service->email ?? ''),
            ],
        ];

        if ($params['serviceid'] <= 0 || trim($params['serverhostname']) === '') {
            continue;
        }

        try {
            SyncService::syncFromPanel($params, $store);
        } catch (\Throwable $e) {
            logModuleCall(
                'midgard',
                'cronSync',
                ['serviceid' => $params['serviceid']],
                $e->getMessage(),
                null,
                []
            );
        }
    }
});

add_hook('ClientAreaProductDetailsOutput', 1, function (array $vars): string {
    $serviceId = midgard_hookResolveProductDetailsServiceId($vars);
    if ($serviceId <= 0) {
        return '';
    }

    $moduleClientAreaPresent = trim((string) ($vars['moduleclientarea'] ?? '')) !== '';

    $params = midgard_hookLoadServiceContext($serviceId);
    if ($params === null) {
        logModuleCall('midgard', 'clientArea.fallbackInjection', [
            'serviceid' => $serviceId,
        ], [
            'injected' => false,
            'reason' => 'service_context_unavailable',
        ], null, []);
        return '';
    }

    try {
        $response = midgard_ClientArea($params);
        $templateVariables = [];
        if (is_array($response['templateVariables'] ?? null)) {
            $templateVariables = $response['templateVariables'];
        } elseif (is_array($response['vars'] ?? null)) {
            $templateVariables = $response['vars'];
        }

        if ($templateVariables === []) {
            logModuleCall('midgard', 'clientArea.fallbackInjection', [
                'serviceid' => $serviceId,
            ], [
                'injected' => false,
                'reason' => 'missing_template_variables',
            ], null, []);
            return '';
        }

        $html = midgard_hookRenderClientAreaFallback($templateVariables);
        logModuleCall('midgard', 'clientArea.fallbackInjection', [
            'serviceid' => $serviceId,
        ], [
            'injected' => true,
            'reason' => 'fallback_rendered',
            'moduleclientarea_present' => $moduleClientAreaPresent,
        ], null, []);

        return $html;
    } catch (\Throwable $e) {
        logModuleCall('midgard', 'clientArea.fallbackInjection', [
            'serviceid' => $serviceId,
        ], [
            'injected' => false,
            'reason' => 'fallback_exception',
            'message' => $e->getMessage(),
        ], null, []);

        return '';
    }
});

add_hook('EmailPreSend', 1, function (array $vars): array {
    try {
        $serviceId = midgard_hookResolveMidgardServiceIdForEmail($vars);
        if ($serviceId <= 0) {
            return [];
        }

        $templateName = midgard_hookResolveConfiguredTemplateForService($serviceId);
        $decision = EmailTemplateGuard::evaluateCredentialsTemplateSend($vars, $templateName);

        if (! $decision['block']) {
            return [];
        }

        logModuleCall('midgard', 'emailGuard.blockedBlankCredentialsTemplate', [
            'serviceid' => $serviceId,
            'userid' => (int) ($vars['userid'] ?? 0),
            'relid' => (int) ($vars['relid'] ?? 0),
            'messagename' => (string) ($vars['messagename'] ?? ''),
        ], [
            'reason' => $decision['reason'],
            'matches_template' => $decision['matches_template'],
            'password_present' => $decision['password_present'],
        ], null, []);

        return ['abortsend' => true];
    } catch (\Throwable $e) {
        logModuleCall('midgard', 'emailGuard.error', [
            'userid' => (int) ($vars['userid'] ?? 0),
            'relid' => (int) ($vars['relid'] ?? 0),
            'messagename' => (string) ($vars['messagename'] ?? ''),
        ], [
            'message' => $e->getMessage(),
        ], null, []);

        return [];
    }
});
