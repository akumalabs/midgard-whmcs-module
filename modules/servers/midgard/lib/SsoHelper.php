<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class SsoHelper
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     */
    public static function buildSsoUrl(ApiClient $client, array $params, array $meta): ?string
    {
        $email = trim((string) ($params['clientsdetails']['email'] ?? ''));
        $serverUuid = trim((string) ($meta['midgard_server_uuid'] ?? ''));

        if ($email === '' || $serverUuid === '') {
            return null;
        }

        $response = $client->issueSsoTicket([
            'email' => $email,
            'server_uuid' => $serverUuid,
        ]);

        $ticket = (string) (($response['data']['ticket'] ?? ''));
        if ($ticket === '') {
            return null;
        }

        $panelBase = Config::panelBaseUrl($params);
        return $panelBase . '/auth/login?sso_ticket=' . rawurlencode($ticket);
    }
}
