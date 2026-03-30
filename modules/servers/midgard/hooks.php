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
            ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
            ->where('tblhosting.id', $serviceId)
            ->value('tblproducts.servertype');

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
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblproducts.servertype', 'midgard')
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
