<?php

declare(strict_types=1);

namespace MidgardWhmcs;

final class MidgardApiException extends \RuntimeException
{
    private int $statusCode;

    /** @var array<string, mixed> */
    private array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $message, int $statusCode = 0, array $payload = [])
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->payload = $payload;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}

final class ApiClient
{
    public const DEFAULT_BASE_PATH = '/api/v1/admin';

    private string $baseUrl;
    private string $token;
    private string $basePath;

    /** @var \CurlHandle|resource|null */
    private $curlHandle = null;

    /**
     * @param string $basePath API base path prefix for every request.
     *        Default "/api/v1/admin" keeps legacy behaviour byte-for-byte;
     *        reseller connections pass "/api/v1/reseller".
     */
    public function __construct(string $baseUrl, string $token, string $basePath = self::DEFAULT_BASE_PATH)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
        $this->basePath = rtrim($basePath, '/');
    }

    public function __destruct()
    {
        if ($this->curlHandle !== null) {
            curl_close($this->curlHandle);
            $this->curlHandle = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        return $this->get($this->basePath . '/servers/random-name');
    }

    /**
     * Register this install's callback URL + HMAC secret with the panel so
     * build-completed webhooks get delivered (POST /webhook-registration,
     * available under both the admin and the reseller base path). The
     * panel upserts per API token; call-site idempotency lives in
     * CallbackRegistrar.
     *
     * @return array<string, mixed>
     */
    public function registerWebhook(string $url, string $secret): array
    {
        return $this->post($this->basePath . '/webhook-registration', [
            'url' => $url,
            'secret' => $secret,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $response = $this->get($this->basePath . '/users?email=' . rawurlencode($email));
        $users = $response['data'] ?? [];
        if (! is_array($users) || count($users) === 0) {
            return null;
        }

        $first = $users[0];
        return is_array($first) ? $first : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createUser(array $payload): array
    {
        return $this->post($this->basePath . '/users', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preflight(array $payload): array
    {
        return $this->post($this->basePath . '/servers/preflight', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLocation(int $locationId): array
    {
        return $this->get($this->basePath . '/locations/' . $locationId);
    }

    /**
     * Fetch all locations for catalog dropdowns.
     *
     * @return array<string, mixed>
     */
    public function getLocations(): array
    {
        return $this->get($this->basePath . '/locations');
    }

    /**
     * Fetch all OS images for catalog dropdowns.
     *
     * @return array<string, mixed>
     */
    public function getOsImages(): array
    {
        return $this->get($this->basePath . '/os-images');
    }

    /**
     * @return array<string, mixed>
     */
    public function randomName(): array
    {
        return $this->get($this->basePath . '/servers/random-name');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createServer(array $payload): array
    {
        return $this->post($this->basePath . '/servers', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getServer(int $serverId): array
    {
        return $this->get($this->basePath . '/servers/' . $serverId);
    }

    /**
     * @return array<string, mixed>
     */
    public function availableIPs(int $serverId, string $type = '', int $perPage = 50): array
    {
        $query = [];
        if ($type !== '') {
            $query['type'] = $type;
        }
        if ($perPage > 0 && $perPage !== 50) {
            $query['per_page'] = $perPage;
        }

        $path = $this->basePath . '/servers/' . $serverId . '/network/available-ips';
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return $this->get($path);
    }

    /**
     * Fetch available addresses for a node (pre-creation resolver).
     *
     * Used by ProvisioningNetworkService::resolveAddressIdsBeforeCreation()
     * to pre-select address_ids[] before server creation, enabling the panel's
     * atomic address binding path.
     *
     * @return array<string, mixed>
     */
    public function availableNodeAddresses(int $nodeId): array
    {
        return $this->get($this->basePath . '/nodes/' . $nodeId . '/addresses/available?per_page=200');
    }

    /**
     * @return array<string, mixed>
     */
    public function assignIP(int $serverId, int $addressId): array
    {
        return $this->post($this->basePath . '/servers/' . $serverId . '/network/assign-ip', [
            'address_id' => $addressId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPrimaryIP(int $serverId, int $addressId): array
    {
        return $this->post($this->basePath . '/servers/' . $serverId . '/network/addresses/' . $addressId . '/set-primary', []);
    }

    /**
     * Normalize primary IP assignment using canonical priority (IPv4 > IPv6).
     *
     * This is the single source-of-truth call to enforce consistent primary
     * IP state after any bulk or sequential assignment operation.
     *
     * @return array<string, mixed>
     */
    public function normalizePrimaryIp(int $serverId): array
    {
        return $this->post($this->basePath . '/servers/' . $serverId . '/normalize-primary-ip', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function installProgress(int $serverId): array
    {
        return $this->get($this->basePath . '/servers/' . $serverId . '/install-progress');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function issueSsoTicket(array $payload): array
    {
        return $this->post($this->basePath . '/sso/tickets', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateServerResources(int $serverId, array $payload): array
    {
        return $this->patch($this->basePath . '/servers/' . $serverId . '/resources', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function suspendServer(int $serverId): array
    {
        return $this->post($this->basePath . '/servers/' . $serverId . '/suspend', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function unsuspendServer(int $serverId): array
    {
        return $this->post($this->basePath . '/servers/' . $serverId . '/unsuspend', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function terminateServer(int $serverId): array
    {
        return $this->delete($this->basePath . '/servers/' . $serverId);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $path): array
    {
        return $this->request('GET', $path, null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function patch(string $path, array $payload): array
    {
        return $this->request('PATCH', $path, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function delete(string $path): array
    {
        return $this->request('DELETE', $path, null);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        // Reuse a single cURL handle across all sequential requests within
        // one ApiClient instance. curl_reset() clears per-call options
        // (POSTFIELDS, CUSTOMREQUEST) while preserving the underlying TCP/TLS
        // connection — this avoids paying a full TLS handshake (~30-80ms) on
        // every call during CreateAccount's 5-6 sequential round-trips.
        if ($this->curlHandle === null) {
            $this->curlHandle = curl_init();
            if ($this->curlHandle === false) {
                throw new MidgardApiException('Unable to initialize HTTP client.');
            }
        } else {
            curl_reset($this->curlHandle);
        }

        curl_setopt($this->curlHandle, CURLOPT_URL, $url);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->token,
        ];

        if ($payload !== null) {
            $json = json_encode($payload);
            if ($json === false) {
                throw new MidgardApiException('Failed to encode request payload.');
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($this->curlHandle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curlHandle, CURLOPT_TIMEOUT, 30);
        curl_setopt($this->curlHandle, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($this->curlHandle, CURLOPT_TCP_KEEPALIVE, 1);

        $rawBody = curl_exec($this->curlHandle);
        $statusCode = (int) curl_getinfo($this->curlHandle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($this->curlHandle);

        if ($rawBody === false) {
            throw new MidgardApiException('HTTP request failed: ' . $curlError, 0);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($rawBody, true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($statusCode >= 400) {
            $message = (string) ($decoded['message'] ?? null);
            if ($message === '' || $message === null) {
                // Non-JSON failure (e.g. 502/504 HTML from a proxy): keep the
                // status and a body snippet so WHMCS logs stay diagnosable.
                $message = "Midgard API request failed (HTTP {$statusCode}).";
                if ($rawBody !== '') {
                    $message .= ' Body: ' . substr(strip_tags($rawBody), 0, 200);
                }
                $decoded = ['status_code' => $statusCode, 'body_snippet' => substr($rawBody, 0, 500)];
            }
            throw new MidgardApiException($message, $statusCode, $decoded);
        }

        return $decoded;
    }
}
