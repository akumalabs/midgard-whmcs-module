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
    private string $baseUrl;
    private string $token;

    /** @var \CurlHandle|resource|null */
    private $curlHandle = null;

    public function __construct(string $baseUrl, string $token)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
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
        return $this->get('/api/v1/admin/servers/random-name');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByEmail(string $email): ?array
    {
        $response = $this->get('/api/v1/admin/users?email=' . rawurlencode($email));
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
        return $this->post('/api/v1/admin/users', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function preflight(array $payload): array
    {
        return $this->post('/api/v1/admin/servers/preflight', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLocation(int $locationId): array
    {
        return $this->get('/api/v1/admin/locations/' . $locationId);
    }

    /**
     * Fetch all locations for catalog dropdowns.
     *
     * @return array<string, mixed>
     */
    public function getLocations(): array
    {
        return $this->get('/api/v1/admin/locations');
    }

    /**
     * Fetch all OS images for catalog dropdowns.
     *
     * @return array<string, mixed>
     */
    public function getOsImages(): array
    {
        return $this->get('/api/v1/admin/os-images');
    }

    /**
     * @return array<string, mixed>
     */
    public function randomName(): array
    {
        return $this->get('/api/v1/admin/servers/random-name');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createServer(array $payload): array
    {
        return $this->post('/api/v1/admin/servers', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getServer(int $serverId): array
    {
        return $this->get('/api/v1/admin/servers/' . $serverId);
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

        $path = '/api/v1/admin/servers/' . $serverId . '/network/available-ips';
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
        return $this->get('/api/v1/admin/nodes/' . $nodeId . '/addresses/available?per_page=200');
    }

    /**
     * @return array<string, mixed>
     */
    public function assignIP(int $serverId, int $addressId): array
    {
        return $this->post('/api/v1/admin/servers/' . $serverId . '/network/assign-ip', [
            'address_id' => $addressId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPrimaryIP(int $serverId, int $addressId): array
    {
        return $this->post('/api/v1/admin/servers/' . $serverId . '/network/addresses/' . $addressId . '/set-primary', []);
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
        return $this->post('/api/v1/admin/servers/' . $serverId . '/normalize-primary-ip', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function installProgress(int $serverId): array
    {
        return $this->get('/api/v1/admin/servers/' . $serverId . '/install-progress');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function issueSsoTicket(array $payload): array
    {
        return $this->post('/api/v1/admin/sso/tickets', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateServerResources(int $serverId, array $payload): array
    {
        return $this->patch('/api/v1/admin/servers/' . $serverId . '/resources', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function suspendServer(int $serverId): array
    {
        return $this->post('/api/v1/admin/servers/' . $serverId . '/suspend', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function unsuspendServer(int $serverId): array
    {
        return $this->post('/api/v1/admin/servers/' . $serverId . '/unsuspend', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function terminateServer(int $serverId): array
    {
        return $this->delete('/api/v1/admin/servers/' . $serverId);
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
            $message = (string) ($decoded['message'] ?? 'Midgard API request failed.');
            throw new MidgardApiException($message, $statusCode, $decoded);
        }

        return $decoded;
    }
}
