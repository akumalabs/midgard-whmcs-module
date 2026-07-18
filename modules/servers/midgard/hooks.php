<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\Config;
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

function midgard_hookResolveConfiguredTemplateForService(int $serviceId, MetadataStore $store): string
{
    if ($serviceId <= 0) {
        return 'Midgard Provisioning Credentials';
    }

    // Authoritative source: the template name that PasswordMailer persisted
    // at send time (resolved via Config::option() with the friendly-key
    // array). This is always correct because it was resolved at the exact
    // moment the email was dispatched, not derived from a positional DB guess.
    $authoritative = trim((string) ($store->get($serviceId)['midgard_welcome_template'] ?? ''));
    if ($authoritative !== '') {
        return $authoritative;
    }

    // Fallback (narrow pre-first-email window): derive from the product's
    // configoptions. WHMCS stores module config options positionally as
    // tblproducts.configoption1..configoption24, in the order returned by
    // midgard_ConfigOptions(). Hardcoding the column index is fragile because
    // any reorder of midgard_ConfigOptions() silently shifts the mapping.
    // Compute the index dynamically so it always tracks the real ordering.
    $index = Config::configOptionIndexForKey('welcome_email_template') ?? 9;
    $column = 'configoption' . $index;

    $templateName = Capsule::table('tblhosting')
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.id', $serviceId)
        ->value('tblproducts.' . $column);

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
    $debugFile = '/tmp/midgard_email_debug.log';
    $messageName = trim((string) ($vars['messagename'] ?? $vars['messageName'] ?? ''));
    
    $debugMsg = "[" . date('Y-m-d H:i:s') . "] EmailPreSend triggered for: '" . $messageName . "'\n";
    $debugMsg .= "Vars: " . json_encode([
        'relid' => $vars['relid'] ?? null,
        'id' => $vars['id'] ?? null,
        'serviceid' => $vars['serviceid'] ?? null,
        'userid' => $vars['userid'] ?? null,
    ]) . "\n";
    @file_put_contents($debugFile, $debugMsg, FILE_APPEND);

    try {
        logModuleCall('midgard', 'emailPreSend.trigger', $vars, [], null, []);
        $serviceId = midgard_hookResolveMidgardServiceIdForEmail($vars);
        
        $debugMsg2 = "Resolved ServiceID: " . $serviceId . "\n";
        @file_put_contents($debugFile, $debugMsg2, FILE_APPEND);

        if ($serviceId <= 0) {
            return [];
        }

        $store = new MetadataStore();
        $configuredTemplate = midgard_hookResolveConfiguredTemplateForService($serviceId, $store);
        
        $debugMsg3 = "Configured Template: '" . $configuredTemplate . "'\n";
        $debugMsg3 .= "Password email already sent? " . ($store->hasPasswordEmailBeenSent($serviceId) ? 'YES' : 'NO') . "\n";
        $debugMsg3 .= "Has password in vars? " . (EmailTemplateGuard::hasMidgardPasswordInVars($vars) ? 'YES' : 'NO') . "\n";
        @file_put_contents($debugFile, $debugMsg3, FILE_APPEND);

        // Guard 1: Block our own configured template if it lacks a password.
        $decision = EmailTemplateGuard::evaluateCredentialsTemplateSend($vars, $configuredTemplate);
        if ($decision['block']) {
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
        }

        // Guard 2: Block WHMCS's built-in "Hosting Account Welcome Email" for
        // Midgard services. WHMCS auto-fires this when the service reaches Active,
        // but it lacks all Midgard custom variables (IPs, password). Our
        // PasswordMailer already sent a complete credentials email earlier in the
        // provisioning flow, so this default template would only confuse the customer.
        // We allow re-sends (e.g. admin manually sending it again from the UI) by
        // checking that the mergefields don't already contain our password.
        $messageName = trim((string) ($vars['messagename'] ?? $vars['messageName'] ?? ''));
        $isWhmcsDefaultWelcome = EmailTemplateGuard::isWhmcsDefaultWelcomeTemplate($messageName);

        if ($isWhmcsDefaultWelcome && ! EmailTemplateGuard::hasMidgardPasswordInVars($vars)) {
            logModuleCall('midgard', 'emailGuard.blockedDefaultWhmcsWelcome', [
                'serviceid' => $serviceId,
                'userid' => (int) ($vars['userid'] ?? 0),
                'relid' => (int) ($vars['relid'] ?? 0),
                'messagename' => $messageName,
            ], [
                'reason' => 'Blocked WHMCS default welcome email for Midgard service — Midgard sends its own credentials email.',
            ], null, []);

            return ['abortsend' => true];
        }

        // Guard 3: Block any welcome/credentials template from re-firing if
        // Midgard's PasswordMailer already successfully delivered credentials
        // for this service. WHMCS may auto-fire the product's configured welcome
        // email template (which may have a custom name, bypassing Guard 2) when
        // the service reaches Active. We allow explicit admin re-sends by
        // checking that the email being sent lacks Midgard credentials — if it
        // already has our password merge fields, it's a legitimate re-send.
        //
        // Scoped ONLY to welcome/credentials templates. Invoices, payment
        // confirmations, suspension notices, and renewal reminders pass through
        // untouched — those must never be suppressed.
        $isConfiguredWelcomeTemplate = $messageName !== '' && strcasecmp($messageName, $configuredTemplate) === 0;
        $isLikelyWelcome = $messageName !== '' && EmailTemplateGuard::isLikelyWelcomeOrCredentialsTemplate($messageName);

        if (
            ($isConfiguredWelcomeTemplate || $isWhmcsDefaultWelcome || $isLikelyWelcome)
            && $store->hasPasswordEmailBeenSent($serviceId)
            && ! EmailTemplateGuard::hasMidgardPasswordInVars($vars)
        ) {
            logModuleCall('midgard', 'emailGuard.blockedDuplicateWelcome', [
                'serviceid' => $serviceId,
                'userid' => (int) ($vars['userid'] ?? 0),
                'relid' => (int) ($vars['relid'] ?? 0),
                'messagename' => $messageName,
            ], [
                'reason' => 'Blocked duplicate welcome email — Midgard credentials already dispatched for this service.',
            ], null, []);

            return ['abortsend' => true];
        }

        return [];
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
