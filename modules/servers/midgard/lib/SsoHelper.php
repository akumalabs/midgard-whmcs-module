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
        return self::buildSsoUrlWithIssuer(
            static fn (array $payload): array => $client->issueSsoTicket($payload),
            $params,
            $meta
        );
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $issueTicket
     * @param array<string, mixed> $params
     * @param array<string, mixed> $meta
     */
    public static function buildSsoUrlWithIssuer(callable $issueTicket, array $params, array $meta): ?string
    {
        $serverUuid = trim((string) ($meta['midgard_server_uuid'] ?? ''));
        $email = trim((string) ($params['clientsdetails']['email'] ?? ''));
        $userId = (int) ($meta['midgard_user_id'] ?? 0);

        if ($serverUuid === '') {
            return null;
        }

        $ticket = null;
        foreach (self::buildSsoPayloadAttempts($serverUuid, $userId, $email) as $payload) {
            try {
                $response = $issueTicket($payload);
                $candidate = trim((string) (($response['data']['ticket'] ?? '')));
                if ($candidate !== '') {
                    $ticket = $candidate;
                    break;
                }
            } catch (\Throwable $e) {
                // Try next fallback payload when available.
            }
        }

        if ($ticket === null) {
            return null;
        }

        $panelBase = Config::panelBaseUrl($params);
        return $panelBase . '/auth/login?sso_ticket=' . rawurlencode($ticket);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function buildSsoPayloadAttempts(string $serverUuid, int $userId, string $email): array
    {
        $attempts = [];

        if ($userId > 0) {
            $attempts[] = [
                'user_id' => $userId,
                'server_uuid' => $serverUuid,
            ];
        }

        if ($email !== '') {
            $attempts[] = [
                'email' => $email,
                'server_uuid' => $serverUuid,
            ];
        }

        return $attempts;
    }
}
