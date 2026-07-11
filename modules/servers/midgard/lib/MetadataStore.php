<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

class MetadataStore implements PasswordDispatchStore
{
    private const META_TABLE = 'mod_midgard_service_meta';
    private const EMAIL_TABLE = 'mod_midgard_email_dispatch';

    /**
     * @return array<string, mixed>
     */
    public function get(int $serviceId): array
    {
        $this->ensureSchema();

        $row = Capsule::table(self::META_TABLE)
            ->where('service_id', $serviceId)
            ->first();

        if (! $row) {
            return $this->defaultMeta();
        }

        return array_merge($this->defaultMeta(), [
            'midgard_user_id' => (string) ($row->midgard_user_id ?? ''),
            'midgard_server_id' => (string) ($row->midgard_server_id ?? ''),
            'midgard_server_uuid' => (string) ($row->midgard_server_uuid ?? ''),
            'midgard_provision_state' => (string) ($row->midgard_provision_state ?? 'installing'),
            'midgard_last_error' => (string) ($row->midgard_last_error ?? ''),
            'midgard_password_email_sent_at' => (string) ($row->midgard_password_email_sent_at ?? ''),
            'midgard_addresses' => $this->decodeAddresses((string) ($row->midgard_addresses ?? '')),
            'midgard_primary_ipv4' => (string) ($row->midgard_primary_ipv4 ?? ''),
            'midgard_primary_ipv6' => (string) ($row->midgard_primary_ipv6 ?? ''),
            'midgard_live_cpu' => $this->nullableIntFromRow($row->midgard_live_cpu ?? null),
            'midgard_live_memory' => $this->nullableIntFromRow($row->midgard_live_memory ?? null),
            'midgard_live_disk' => $this->nullableIntFromRow($row->midgard_live_disk ?? null),
            'midgard_live_bandwidth_limit' => $this->nullableIntFromRow($row->midgard_live_bandwidth_limit ?? null),
            'midgard_live_backup_limit' => $this->nullableIntFromRow($row->midgard_live_backup_limit ?? null),
            'midgard_live_snapshot_limit' => $this->nullableIntFromRow($row->midgard_live_snapshot_limit ?? null),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert(int $serviceId, array $data): void
    {
        $this->ensureSchema();

        $payload = [
            'service_id' => $serviceId,
            'midgard_user_id' => (string) ($data['midgard_user_id'] ?? ''),
            'midgard_server_id' => (string) ($data['midgard_server_id'] ?? ''),
            'midgard_server_uuid' => (string) ($data['midgard_server_uuid'] ?? ''),
            'midgard_provision_state' => (string) ($data['midgard_provision_state'] ?? 'installing'),
            'midgard_last_error' => (string) ($data['midgard_last_error'] ?? ''),
            'midgard_password_email_sent_at' => (string) ($data['midgard_password_email_sent_at'] ?? ''),
            'midgard_addresses' => $this->encodeAddresses($data['midgard_addresses'] ?? []),
            'midgard_primary_ipv4' => (string) ($data['midgard_primary_ipv4'] ?? ''),
            'midgard_primary_ipv6' => (string) ($data['midgard_primary_ipv6'] ?? ''),
            'midgard_live_cpu' => $this->nullableInt($data['midgard_live_cpu'] ?? null),
            'midgard_live_memory' => $this->nullableInt($data['midgard_live_memory'] ?? null),
            'midgard_live_disk' => $this->nullableInt($data['midgard_live_disk'] ?? null),
            'midgard_live_bandwidth_limit' => $this->nullableInt($data['midgard_live_bandwidth_limit'] ?? null),
            'midgard_live_backup_limit' => $this->nullableInt($data['midgard_live_backup_limit'] ?? null),
            'midgard_live_snapshot_limit' => $this->nullableInt($data['midgard_live_snapshot_limit'] ?? null),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $exists = Capsule::table(self::META_TABLE)->where('service_id', $serviceId)->exists();
        if ($exists) {
            Capsule::table(self::META_TABLE)->where('service_id', $serviceId)->update($payload);
            return;
        }

        $payload['created_at'] = $payload['updated_at'];
        Capsule::table(self::META_TABLE)->insert($payload);
    }

    public function clear(int $serviceId): void
    {
        $this->ensureSchema();
        Capsule::table(self::META_TABLE)->where('service_id', $serviceId)->delete();
    }

    public function claimPasswordDispatch(int $serviceId, string $serverUuid): ?string
    {
        $this->ensureSchema();

        $dispatchKey = IdempotencyGuard::buildDispatchKey($serviceId, $serverUuid);
        $dispatchHash = IdempotencyGuard::hash($dispatchKey);

        try {
            Capsule::table(self::EMAIL_TABLE)->insert([
                'dispatch_hash' => $dispatchHash,
                'service_id' => $serviceId,
                'server_uuid' => $serverUuid,
                'sent_at' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return $dispatchHash;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function finalizePasswordDispatch(int $serviceId, string $dispatchHash): void
    {
        $this->ensureSchema();

        $now = date('Y-m-d H:i:s');

        Capsule::table(self::EMAIL_TABLE)
            ->where('dispatch_hash', $dispatchHash)
            ->update(['sent_at' => $now]);

        $meta = $this->get($serviceId);
        $meta['midgard_password_email_sent_at'] = $now;
        $this->upsert($serviceId, $meta);
    }

    public function releasePasswordDispatch(string $dispatchHash): void
    {
        $this->ensureSchema();

        Capsule::table(self::EMAIL_TABLE)
            ->where('dispatch_hash', $dispatchHash)
            ->whereNull('sent_at')
            ->delete();
    }

    /**
     * Check whether a Midgard credentials email was already successfully
     * dispatched for this service. Used by the EmailPreSend hook to block
     * duplicate welcome emails that WHMCS auto-fires when the service
     * reaches Active status.
     */
    public function hasPasswordEmailBeenSent(int $serviceId): bool
    {
        $this->ensureSchema();

        $meta = $this->get($serviceId);
        $sentAt = trim((string) ($meta['midgard_password_email_sent_at'] ?? ''));
        if ($sentAt !== '') {
            return true;
        }

        // Also check the dispatch table directly as a fallback.
        return Capsule::table(self::EMAIL_TABLE)
            ->where('service_id', $serviceId)
            ->whereNotNull('sent_at')
            ->exists();
    }

    private function ensureSchema(): void
    {
        $schema = Capsule::schema();

        if (! $schema->hasTable(self::META_TABLE)) {
            $schema->create(self::META_TABLE, function ($table): void {
                $table->integer('service_id')->primary();
                $table->string('midgard_user_id', 64)->nullable();
                $table->string('midgard_server_id', 64)->nullable();
                $table->string('midgard_server_uuid', 128)->nullable();
                $table->string('midgard_provision_state', 32)->default('installing');
                $table->text('midgard_last_error')->nullable();
                $table->string('midgard_password_email_sent_at', 32)->nullable();
                $table->text('midgard_addresses')->nullable();
                $table->string('midgard_primary_ipv4', 64)->nullable();
                $table->string('midgard_primary_ipv6', 128)->nullable();
                $table->bigInteger('midgard_live_cpu')->nullable();
                $table->bigInteger('midgard_live_memory')->nullable();
                $table->bigInteger('midgard_live_disk')->nullable();
                $table->bigInteger('midgard_live_bandwidth_limit')->nullable();
                $table->bigInteger('midgard_live_backup_limit')->nullable();
                $table->bigInteger('midgard_live_snapshot_limit')->nullable();
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

        $this->ensureMetaColumns($schema);

        if (! $schema->hasTable(self::EMAIL_TABLE)) {
            $schema->create(self::EMAIL_TABLE, function ($table): void {
                $table->string('dispatch_hash', 64)->primary();
                $table->integer('service_id');
                $table->string('server_uuid', 128);
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('created_at');
                $table->index(['service_id']);
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultMeta(): array
    {
        return [
            'midgard_user_id' => '',
            'midgard_server_id' => '',
            'midgard_server_uuid' => '',
            'midgard_provision_state' => 'installing',
            'midgard_last_error' => '',
            'midgard_password_email_sent_at' => '',
            'midgard_addresses' => [],
            'midgard_primary_ipv4' => '',
            'midgard_primary_ipv6' => '',
            'midgard_live_cpu' => null,
            'midgard_live_memory' => null,
            'midgard_live_disk' => null,
            'midgard_live_bandwidth_limit' => null,
            'midgard_live_backup_limit' => null,
            'midgard_live_snapshot_limit' => null,
        ];
    }

    private function ensureMetaColumns($schema): void
    {
        $metaColumns = [
            'midgard_addresses' => static function ($table): void {
                $table->text('midgard_addresses')->nullable();
            },
            'midgard_primary_ipv4' => static function ($table): void {
                $table->string('midgard_primary_ipv4', 64)->nullable();
            },
            'midgard_primary_ipv6' => static function ($table): void {
                $table->string('midgard_primary_ipv6', 128)->nullable();
            },
            'midgard_live_cpu' => static function ($table): void {
                $table->bigInteger('midgard_live_cpu')->nullable();
            },
            'midgard_live_memory' => static function ($table): void {
                $table->bigInteger('midgard_live_memory')->nullable();
            },
            'midgard_live_disk' => static function ($table): void {
                $table->bigInteger('midgard_live_disk')->nullable();
            },
            'midgard_live_bandwidth_limit' => static function ($table): void {
                $table->bigInteger('midgard_live_bandwidth_limit')->nullable();
            },
            'midgard_live_backup_limit' => static function ($table): void {
                $table->bigInteger('midgard_live_backup_limit')->nullable();
            },
            'midgard_live_snapshot_limit' => static function ($table): void {
                $table->bigInteger('midgard_live_snapshot_limit')->nullable();
            },
        ];

        foreach ($metaColumns as $column => $definition) {
            if ($schema->hasColumn(self::META_TABLE, $column)) {
                continue;
            }

            $schema->table(self::META_TABLE, static function ($table) use ($definition): void {
                $definition($table);
            });
        }
    }

    /**
     * @param mixed $value
     */
    private function nullableInt($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableIntFromRow($value): ?int
    {
        return $this->nullableInt($value);
    }

    /**
     * @param mixed $addresses
     */
    private function encodeAddresses($addresses): string
    {
        if (! is_array($addresses)) {
            return '[]';
        }

        $payload = [];
        foreach ($addresses as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payload[] = [
                'id' => (int) ($row['id'] ?? 0),
                'address' => trim((string) ($row['address'] ?? '')),
                'type' => strtolower(trim((string) ($row['type'] ?? ''))),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $json === false ? '[]' : $json;
    }

    /**
     * @return array<int, array{id: int, address: string, type: string, is_primary: bool}>
     */
    private function decodeAddresses(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        $addresses = [];
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }

            $address = trim((string) ($row['address'] ?? ''));
            $type = strtolower(trim((string) ($row['type'] ?? '')));
            if ($address === '' || ! in_array($type, ['ipv4', 'ipv6'], true)) {
                continue;
            }

            $addresses[] = [
                'id' => (int) ($row['id'] ?? 0),
                'address' => $address,
                'type' => $type,
                'is_primary' => (bool) ($row['is_primary'] ?? false),
            ];
        }

        return $addresses;
    }
}
