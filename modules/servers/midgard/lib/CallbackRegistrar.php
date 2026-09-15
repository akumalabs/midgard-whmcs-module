<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Registers this WHMCS install as a webhook receiver with the Midgard panel
 * (POST /webhook-registration {url, secret}), so the panel pushes
 * server.build.completed callbacks to modules/servers/midgard/callback.php.
 *
 * Idempotent per install:
 *   - The HMAC secret (64 hex chars) is generated ONCE and persisted in the
 *     install-wide KV table (mod_midgard_install_settings) BEFORE the HTTP
 *     call, so a failed registration retries later with the SAME secret
 *     (the panel upserts per token, so re-registration is harmless anyway).
 *   - A "registered" flag is only written AFTER a successful response; until
 *     then every call re-attempts the registration.
 *
 * Called from midgard_TestConnection() and the start of midgard_CreateAccount().
 * Callers MUST treat a registration failure as non-fatal (log only).
 */
final class CallbackRegistrar
{
    public const SETTING_SECRET = 'callback_secret';
    public const SETTING_REGISTERED = 'callback_registered';

    public const CALLBACK_PATH = '/modules/servers/midgard/callback.php';

    /**
     * Run the idempotent registration flow.
     *
     * @param array<string, mixed> $params WHMCS module params (server* keys).
     * @param MetadataStore $store metadata store (owns the install KV table).
     * @param callable|null $clientFactory optional factory returning an object
     *        with registerWebhook(string $url, string $secret): array — used
     *        by tests; defaults to a mode-aware ApiClient.
     * @param int|null $now optional fixed timestamp (tests).
     * @return array{status: string, url: string, reason: string}
     *         status: 'registered' (POST succeeded this run) or 'skipped'
     *         (flag already set — nothing sent).
     * @throws \Throwable when the panel POST fails (flag stays unset; the
     *         next call retries with the same persisted secret).
     */
    public function register(
        array $params,
        MetadataStore $store,
        ?callable $clientFactory = null,
        ?int $now = null
    ): array {
        $url = self::callbackUrl($params);

        // Ensure the secret exists FIRST (persisted before any HTTP traffic):
        // a crash mid-registration then retries with the identical secret.
        $secret = (string) $store->getInstallSetting(self::SETTING_SECRET);
        if ($secret === '') {
            $secret = self::generateSecret();
            $store->setInstallSetting(self::SETTING_SECRET, $secret);
        }

        if ($store->getInstallSetting(self::SETTING_REGISTERED) !== null) {
            return ['status' => 'skipped', 'url' => $url, 'reason' => ''];
        }

        if ($url === '') {
            // Cannot resolve this install's own URL (CLI cron without
            // SERVER_NAME and no systemurl/IP fallback). Do NOT set the
            // flag — retry on a later call when the URL is resolvable.
            throw new \RuntimeException(
                'Unable to resolve the WHMCS System URL for callback registration.'
            );
        }

        $client = $clientFactory !== null
            ? $clientFactory($params)
            : self::defaultClient($params);

        $client->registerWebhook($url, $secret);

        $store->setInstallSetting(
            self::SETTING_REGISTERED,
            date('Y-m-d H:i:s', $now ?? time())
        );

        return ['status' => 'registered', 'url' => $url, 'reason' => ''];
    }

    /**
     * The exact callback endpoint URL that was (or will be) registered.
     */
    public static function callbackUrl(array $params): string
    {
        $base = self::resolveSystemUrl($params);
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . self::CALLBACK_PATH;
    }

    /**
     * Resolve the WHMCS System URL for this install.
     *
     * Verified sources, in priority order:
     *   1. $params['systemurl'] — WHMCS passes the System URL into module
     *      params on client-area and some admin paths.
     *   2. The "SystemURL" constant — defined by WHMCS ≥ 8.11 in
     *      whmcs/inc/inifuncs.php after the init bootstraps.
     *   3. The server row's configured IP address (tblservers.ipaddress) —
     *      set by the admin on the same server entry that holds the API
     *      token; present in cron/console contexts.
     *   4. $_SERVER['SERVER_NAME'] / HTTP_HOST / SERVER_ADDR — the
     *      traditional fallback for web-served requests.
     *
     * Returns '' when nothing resolvable is available; the caller surfaces
     * that instead of guessing.
     *
     * @param array<string, mixed> $params
     */
    public static function resolveSystemUrl(array $params): string
    {
        $fromParams = trim((string) ($params['systemurl'] ?? ''));
        if ($fromParams !== '') {
            return $fromParams;
        }

        if (defined('SystemURL')) {
            $constant = trim((string) constant('SystemURL'));
            if ($constant !== '') {
                return $constant;
            }
        }

        $hostname = trim((string) ($params['serverhostname'] ?? ''));
        if ($hostname !== '') {
            try {
                $ip = trim((string) Capsule::table('tblservers')
                    ->where('hostname', $hostname)
                    ->orderBy('id')
                    ->value('ipaddress'));
                if ($ip !== '') {
                    return 'http://' . $ip;
                }
            } catch (\Throwable $e) {
                // Table unavailable (unit tests / partial boot) — fall through.
            }
        }

        foreach (['SERVER_NAME', 'HTTP_HOST', 'SERVER_ADDR'] as $key) {
            $candidate = isset($_SERVER[$key]) && is_string($_SERVER[$key])
                ? trim($_SERVER[$key])
                : '';
            if ($candidate !== '' && filter_var('http://' . $candidate, FILTER_VALIDATE_URL) !== false) {
                return 'http://' . $candidate;
            }
        }

        return '';
    }

    /**
     * 64-hex-character HMAC secret (256 bit of entropy).
     */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Mode-aware API client (reseller connections must register via their
     * own scoped base path — the webhook belongs to the reseller token).
     */
    private static function defaultClient(array $params): ApiClient
    {
        return new ApiClient(
            Config::panelBaseUrl($params),
            Config::apiToken($params),
            Config::basePath($params)
        );
    }
}
