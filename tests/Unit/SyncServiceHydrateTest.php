<?php

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\SyncService;
use PHPUnit\Framework\TestCase;

/**
 * Async fast path: hydrateFromServerData() must fill module meta from the
 * createServer() 201 payload WITHOUT any extra API round-trip.
 *
 * @covers \MidgardWhmcs\SyncService::hydrateFromServerData
 */
class SyncServiceHydrateTest extends TestCase
{
    private MetadataStore $store;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $ref = new \ReflectionProperty(MetadataStore::class, 'schemaReady');
        $ref->setAccessible(true);
        $ref->setValue(null, false);

        $this->store = new MetadataStore();
        $this->store->get(1);
    }

    private function serverPayload(): array
    {
        return [
            'id' => 442,
            'uuid' => 'uuid-442',
            'name' => 'Nimble Module',
            'hostname' => 'nimble-module',
            'status' => 'running',
            'addresses' => [
                ['id' => 1, 'type' => 'ipv4', 'address' => '104.250.119.38', 'is_primary' => true],
                ['id' => 2, 'type' => 'ipv6', 'address' => '2a10:1234::/64', 'is_primary' => true],
            ],
        ];
    }

    public function test_hydrates_meta_from_payload_without_round_trips(): void
    {
        $this->store->upsert(51, ['midgard_server_id' => '442', 'midgard_server_uuid' => 'uuid-442']);

        $summary = SyncService::hydrateFromServerData(51, $this->serverPayload(), $this->store);

        $this->assertSame('104.250.119.38', $summary['primary_ipv4']);
        $meta = $this->store->get(51);
        $this->assertSame('104.250.119.38', $meta['midgard_primary_ipv4']);
        $this->assertSame('2a10:1234::/64', $meta['midgard_primary_ipv6']);
        $this->assertCount(2, $meta['midgard_addresses']);
    }

    public function test_empty_payload_returns_blank_summary_without_meta_writes(): void
    {
        $this->store->upsert(52, ['midgard_server_id' => '9']);
        $before = $this->store->get(52);

        $summary = SyncService::hydrateFromServerData(52, [], $this->store);

        $this->assertSame('', $summary['primary_ipv4']);
        $this->assertSame($before, $this->store->get(52), 'blank payload must not touch meta');
    }

    public function test_addressless_payload_does_not_mark_hydrated(): void
    {
        $this->store->upsert(53, ['midgard_server_id' => '10']);

        $summary = SyncService::hydrateFromServerData(53, [
            'id' => 10,
            'uuid' => 'uuid-10',
            'name' => 'Bare',
            'addresses' => [],
        ], $this->store);

        // Caller must see a blank summary so the legacy chain still runs.
        $this->assertSame('', $summary['primary_ipv4']);
        $this->assertSame('', $summary['primary_ipv6']);
    }
}
