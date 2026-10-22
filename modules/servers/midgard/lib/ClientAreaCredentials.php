<?php

declare(strict_types=1);

namespace MidgardWhmcs;

/**
 * Client-area credentials display helper.
 *
 * Derives the default OS login user from the provisioned image distro and
 * fetches the stored server password for the on-demand reveal action.
 *
 * The password NEVER renders into the initial client-area HTML: the grid
 * shows a masked placeholder and the plaintext is fetched on click via the
 * ajax proxy (ajax.php action=credentials), which is session+CSRF gated.
 */
final class ClientAreaCredentials
{
    /**
     * Default login user for an image distro: Windows images use
     * Administrator, everything else (ubuntu/debian/centos/rocky/alma/…)
     * uses root. Empty/unknown distros fall back to root — the fleet is
     * Linux-majority and root is the historical default for cloud images.
     *
     * @param string|null $distro os_images.distro (e.g. "windows", "debian")
     */
    public static function deriveUser(?string $distro): string
    {
        return str_contains(strtolower(trim((string) $distro)), 'windows')
            ? 'Administrator'
            : 'root';
    }

    /**
     * Fetch the stored OS password via the admin panel endpoint.
     * Returns the plaintext string; throws MidgardApiException on 404
     * (password not stored) — callers map that to a customer-readable
     * message and never expose the raw exception text.
     */
    public static function fetchPassword(ApiClient $client, int $serverId): string
    {
        $response = $client->getServerPassword($serverId);

        return (string) ($response['data']['password'] ?? '');
    }
}
