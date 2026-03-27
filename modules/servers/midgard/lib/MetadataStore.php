<?php

declare(strict_types=1);

namespace MidgardWhmcs;

use Illuminate\Database\Capsule\Manager as Capsule;

final class MetadataStore implements PasswordDispatchStore
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
                $table->dateTime('created_at');
                $table->dateTime('updated_at');
            });
        }

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
        ];
    }
}
