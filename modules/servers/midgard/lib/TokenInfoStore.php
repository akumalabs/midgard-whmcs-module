<?php

declare(strict_types=1);

namespace MidgardWhmcs;

/**
 * Connection bootstrap (reseller Fase B): asks the panel WHICH KIND of
 * token this connection uses, via GET /api/v1/token-info — an endpoint any
 * valid token can reach regardless of scope.
 *
 * Why: an unprefixed reseller token is indistinguishable from an admin
 * token by looking at the Access Hash alone. The prefix ("reseller|...")
 * stays supported as an explicit override, but discovery removes the need
 * to remember it — the panel is the single source of truth.
 *
 * Result is cached install-wide (mod_midgard_install_settings) because it
 * can never change for a given token value: re-running TestConnection is
 * the only thing that rewrites the cache.
 */
final class TokenInfoStore
{
    private const CACHE_KEY = 'connection_token_info';

    /** @var array<string, mixed>|null|\Throwable memoized discovery result (null = unknown/not run) */
    private $info = null;

    private MetadataStore $store;

    public function __construct(MetadataStore $store)
    {
        $this->store = $store;
    }

    /**
     * Resolve the effective base path for this connection.
     *
     * Order: explicit "reseller|" prefix (admin may deliberately pin the
     * legacy path) → cached discovery → configured default from Config.
     *
     * @param array<string, mixed> $params
     */
    public function effectiveBasePath(array $params): string
    {
        // Explicit override always wins — never second-guess the operator.
        if (Config::mode($params) === Config::MODE_RESELLER) {
            return Config::RESELLER_BASE_PATH;
        }

        $discovered = $this->info();
        if (is_array($discovered) && isset($discovered['api_base_path']) && is_string($discovered['api_base_path']) && $discovered['api_base_path'] !== '') {
            return $discovered['api_base_path'];
        }

        return Config::basePath($params);
    }

    /**
     * Normalize a /api/v1/token-info payload into the cached-shape.
     *
     * LIVE PANEL CONTRACT (panel e0b36ae / v2026.09.16.0400):
     *   {"type": "admin"|"reseller", "base_path": "/api/v1/…",
     *    "dormant": bool, "user": {…}, "reseller": {…}|null}
     *
     * A DORMANT reseller token reports the admin path (it cannot open
     * either surface yet), but the USEFUL error lives on the reseller
     * surface (403 dormant_token — "flag the owner as Reseller"), so we
     * cache the RESELLER path: later calls surface the actionable message
     * instead of a generic admin-surface denial.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null null = unexpected shape, keep cache
     */
    public static function normalize(array $payload): ?array
    {
        $type = $payload['type'] ?? null;
        if (! is_string($type) || $type === '') {
            return null;
        }

        $basePath = $payload['base_path'] ?? null;
        $dormant = ($payload['dormant'] ?? false) === true;

        $effective = $dormant
            ? Config::RESELLER_BASE_PATH
            : (is_string($basePath) && $basePath !== '' ? $basePath : null);

        return [
            'token_type' => $type,
            'api_base_path' => $effective ?? Config::ADMIN_BASE_PATH,
            'owner_name' => (string) (($payload['user']['name'] ?? null) ?? ''),
            'discovered_at' => date('c'),
        ];
    }

    /**
     * Run discovery against the panel and cache it. Network errors are
     * swallowed (return null): discovery is a convenience — a panel that
     * predates token-info simply keeps the legacy behaviour.
     *
     * @param array<string, mixed> $params
     */
    public function discover(array $params): void
    {
        try {
            $client = new ApiClient(
                Config::panelBaseUrl($params),
                Config::apiToken($params),
                Config::ADMIN_BASE_PATH
            );

            $response = $client->getTokenInfo();
            $payload = is_array($response) ? $response : [];

            // Tolerate an envelope {"data": {…}} as well as the bare payload.
            if (isset($payload['data']) && is_array($payload['data'])) {
                $payload = $payload['data'];
            }

            $normalized = self::normalize($payload);
            if ($normalized === null) {
                return; // Unexpected shape — keep whatever is cached.
            }

            $this->info = $normalized;
            $this->store->setInstallSetting(self::CACHE_KEY, (string) json_encode($this->info));
        } catch (\Throwable $e) {
            // Discovery must never break TestConnection (older panel, network
            // blip). Legacy prefix/default behaviour stays intact.
        }
    }

    /**
     * Cached discovery result, or null when never discovered (fresh
     * install, or discovery has never succeeded for this token).
     *
     * @return array<string, mixed>|null
     */
    public function info(): ?array
    {
        if ($this->info !== null) {
            return is_array($this->info) ? $this->info : null;
        }

        $raw = $this->store->getInstallSetting(self::CACHE_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Static resolver used by EVERY ApiClient construction site
     * (midgard_client, SyncService, CallbackRegistrar): explicit prefix →
     * cached discovery → Config default. A fresh install without a cached
     * answer behaves exactly like the legacy module (admin base path).
     *
     * @param array<string, mixed> $params
     */
    public static function resolveBasePath(array $params): string
    {
        if (Config::mode($params) === Config::MODE_RESELLER) {
            return Config::RESELLER_BASE_PATH;
        }

        try {
            $raw = (new MetadataStore())->getInstallSetting(self::CACHE_KEY);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)
                    && isset($decoded['api_base_path'])
                    && is_string($decoded['api_base_path'])
                    && $decoded['api_base_path'] !== '') {
                    return $decoded['api_base_path'];
                }
            }
        } catch (\Throwable $e) {
            // KV unavailable — fall through to the configured default.
        }

        return Config::basePath($params);
    }
}
