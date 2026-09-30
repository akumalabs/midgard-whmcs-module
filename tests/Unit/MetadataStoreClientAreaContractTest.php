<?php

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Contract lock for the client-area meta keys (runtime status, stats
 * snapshot, OS name). This store silently DROPS any key that is not
 * registered in get() + upsert() + defaultMeta() + ensureMetaColumns()
 * — the sealed-password incident class. midgard_runtime_status once
 * slipped through exactly that way: SyncService wrote it, upsert()
 * discarded it, and the client area action bar resolved to UNKNOWN
 * forever while the server was actually running.
 *
 * @covers \MidgardWhmcs\MetadataStore
 */
class MetadataStoreClientAreaContractTest extends TestCase
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
        $this->store->get(1); // triggers ensureSchema()
    }

    public function test_client_area_meta_keys_round_trip_through_upsert_and_get(): void
    {
        $statsPayload = json_encode([
            'status' => 'running',
            'uptime' => 648,
            'cpu_percent' => 2.11,
            'mem' => 931020800,
            'maxmem' => 1073741824,
            'disk' => 2684354560,
            'maxdisk' => 53687091200,
            'collected_at' => '2026-09-28T01:55:21+00:00',
            'bandwidth_usage' => 123456789,
        ]);

        $this->store->upsert(42, [
            'midgard_server_id' => '145',
            'midgard_runtime_status' => 'running',
            'midgard_stats_json' => (string) $statsPayload,
            'midgard_os_name' => 'Debian 13 (Trixie)',
            'midgard_vmid' => '116',
            'midgard_location' => 'Dallas',
            'midgard_server_name' => 'Debian Test',
            'midgard_server_hostname' => 'debian-test',
        ]);

        $meta = $this->store->get(42);

        $this->assertSame('running', $meta['midgard_runtime_status']);
        $this->assertSame($statsPayload, $meta['midgard_stats_json']);
        $this->assertSame('Debian 13 (Trixie)', $meta['midgard_os_name']);
        $this->assertSame('145', $meta['midgard_server_id']);
        $this->assertSame('116', $meta['midgard_vmid']);
        $this->assertSame('Dallas', $meta['midgard_location']);
        $this->assertSame('Debian Test', $meta['midgard_server_name']);
        $this->assertSame('debian-test', $meta['midgard_server_hostname']);
    }

    public function test_schema_creates_client_area_columns(): void
    {
        $schema = Capsule::schema();

        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_runtime_status'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_stats_json'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_os_name'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_vmid'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_location'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_server_name'));
        $this->assertTrue($schema->hasColumn('mod_midgard_service_meta', 'midgard_server_hostname'));
    }

    public function test_get_defaults_carry_unknown_runtime_status(): void
    {
        $meta = $this->store->get(999); // no row

        $this->assertSame('unknown', $meta['midgard_runtime_status']);
        $this->assertSame('', $meta['midgard_stats_json']);
        $this->assertSame('', $meta['midgard_os_name']);
        $this->assertSame('', $meta['midgard_vmid']);
        $this->assertSame('', $meta['midgard_location']);
        $this->assertSame('', $meta['midgard_server_name']);
        $this->assertSame('', $meta['midgard_server_hostname']);
    }
}
