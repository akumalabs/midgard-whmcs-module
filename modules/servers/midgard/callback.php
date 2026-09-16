<?php

declare(strict_types=1);

/**
 * Midgard build-completed callback receiver (contract f2-c2).
 *
 * The panel POSTs the server.build.completed envelope here (URL registered
 * via CallbackRegistrar) signed with the install's shared secret:
 *   X-Midgard-Signature: sha256=hash_hmac('sha256', timestamp . "." . rawBody, secret)
 *
 * Responses are ALWAYS JSON:
 *   200 {status: "sent"|"already_sent"|"not_ready"|"no_service"}
 *   401 — signature invalid or timestamp stale (outside ±300s)
 *   500 — internal error
 *
 * The AfterCronJob flush worker in hooks.php remains fully intact and keeps
 * delivering any queued credentials email independently of this endpoint.
 */

if (! defined('WHMCS')) {
    // Light bootstrap, mirroring the hooks.php pattern: load the WHMCS
    // environment (defines the WHMCS constant, DB capsule, module helpers)
    // when this endpoint is hit over HTTP outside an existing WHMCS boot.
    // hooks.php does the equivalent via the init.php includes.
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

// Belt and suspenders: the callback classes are normally loaded by the
// require block in midgard.php — load them here as well so this endpoint
// can never 500 ("class not found") on a partial or stale include graph.
require_once __DIR__ . '/lib/CallbackRegistrar.php';
require_once __DIR__ . '/lib/CallbackRequestVerifier.php';
require_once __DIR__ . '/lib/CallbackHandler.php';

header('Content-Type: application/json');

try {
    $rawBody = (string) file_get_contents('php://input');
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    if ($headers === false || $headers === []) {
        // Fall back to $_SERVER when getallheaders() is unavailable.
        $headers = $_SERVER;
    }

    $secret = (string) midgard_store()->getInstallSetting(
        \MidgardWhmcs\CallbackRegistrar::SETTING_SECRET
    );

    $verification = \MidgardWhmcs\CallbackRequestVerifier::verify($secret, $headers, $rawBody);
    if (! $verification['ok']) {
        http_response_code($verification['code']);
        echo json_encode(['status' => 'error', 'message' => $verification['error']]);
        exit;
    }

    $envelope = \MidgardWhmcs\CallbackRequestVerifier::parseEnvelope($rawBody);
    if (! $envelope['ok']) {
        // Signed but malformed for this flow — nothing actionable, yet the
        // request itself is authentic: acknowledge with 200 so the panel
        // does not burn its retry budget on a payload we will never accept.
        echo json_encode(['status' => 'no_service']);
        exit;
    }

    $handler = new \MidgardWhmcs\CallbackHandler();
    echo json_encode(['status' => $handler->handle($envelope['data'])]);
} catch (\Throwable $e) {
    if (function_exists('logModuleCall')) {
        logModuleCall('midgard', 'callback.internalError', [], $e->getMessage(), null, []);
    }

    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'internal error']);
}
