<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\SyncService;

if (! defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/midgard.php';

if (! function_exists('add_hook')) {
    return;
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
