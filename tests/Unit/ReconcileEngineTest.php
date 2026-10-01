<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\ReconcileEngine;
use PHPUnit\Framework\TestCase;

/**
 * Scaling plan v2 Fase 3 — hybrid reconciliation engine.
 *
 * Strategy: services WITHOUT a configured panel row are skipped cheaply
 * (no HTTP), so rotation/cursor/lock/suspect behavior is exercised for real
 * against sqlite without any network I/O. The budget check is verified as a
 * pure function.
 *
 * @covers \MidgardWhmcs\ReconcileEngine
 */
final class ReconcileEngineTest extends TestCase
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
        $this->store->get(1); // ensureSchema (meta + dispatch + install settings)

        Capsule::schema()->create('tblproducts', static function ($table): void {
            $table->increments('id');
            $table->string('servertype')->default('other');
        });
        Capsule::schema()->create('tblhosting', static function ($table): void {
            $table->increments('id');
            $table->integer('userid')->default(0);
            $table->integer('packageid')->default(0);
            $table->string('domainstatus')->default('Active');
        });
    }

    private function seedMidgardService(int $serviceId, string $status = 'Active', string $runtime = ''): void
    {
        if (! Capsule::table('tblproducts')->where('id', 7)->exists()) {
            Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        }

        Capsule::table('tblhosting')->insert([
            'id' => $serviceId,
            'userid' => 3,
            'packageid' => 7,
            'domainstatus' => $status,
        ]);

        if ($runtime !== '') {
            $meta = $this->store->get($serviceId);
            $meta['midgard_runtime_status'] = $runtime;
            $this->store->upsert($serviceId, $meta);
        }
    }

    public function test_suspects_include_transitional_runtime_and_stuck_dispatch(): void
    {
        $this->seedMidgardService(10, runtime: 'rebuilding');
        $this->seedMidgardService(11, runtime: 'running'); // settled — NOT a suspect
        $this->seedMidgardService(12, runtime: ''); // no meta yet — not a suspect

        // Stuck dispatch for service 12 (queued, never sent).
        Capsule::table('mod_midgard_email_dispatch')->insert([
            'service_id' => 12,
            'dispatch_hash' => 'h1',
            'server_uuid' => 'u',
            'queued_at' => date('Y-m-d H:i:s'),
            'sent_at' => null,
            'queue_attempts' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $suspects = (new ReconcileEngine)->suspectServiceIds();

        $this->assertSame([10, 12], $suspects);
    }

    public function test_sweep_rotates_through_all_services_and_wraps(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->seedMidgardService($i);
        }

        $stats = (new ReconcileEngine)->run($this->store);

        // No panel row configured → every service is cheaply skipped (no
        // HTTP), but the rotation still walks all of them and wraps.
        $this->assertSame(60, $stats['swept']);
        $this->assertSame(0, $stats['calls']);
        $this->assertFalse($stats['skipped']);
        $this->assertSame('0', $this->store->getInstallSetting('reconcile_cursor'));
    }

    public function test_sweep_continues_from_stored_cursor(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->seedMidgardService($i);
        }

        // Pretend the previous tick stopped at 30.
        $this->store->setInstallSetting('reconcile_cursor', '30');

        $stats = (new ReconcileEngine)->run($this->store);

        // The 30 services after the cursor are swept, then the rotation
        // hits the end, wraps, and resets the cursor for the next tick.
        $this->assertSame(30, $stats['swept']);
        $this->assertSame('0', $this->store->getInstallSetting('reconcile_cursor'));
    }

    public function test_lock_skips_fresh_and_takes_over_stale(): void
    {
        $this->seedMidgardService(1);

        // Fresh lock from another tick → skipped.
        $this->store->setInstallSetting('reconcile_lock', '999|'.gmdate('c'));
        $stats = (new ReconcileEngine)->run($this->store);
        $this->assertTrue($stats['skipped']);
        // Lock content untouched by the skip.
        $this->assertSame('999|'.gmdate('c'), $this->store->getInstallSetting('reconcile_lock'));

        // Stale lock (dead cron) → taken over and the tick runs.
        $stale = gmdate('c', time() - (ReconcileEngine::LOCK_STALE_SECONDS + 60));
        $this->store->setInstallSetting('reconcile_lock', '999|'.$stale);
        $stats = (new ReconcileEngine)->run($this->store);
        $this->assertFalse($stats['skipped']);
        $this->assertSame(1, $stats['swept']);
        // Lock released at the end of the tick ('' reads back as null per
        // getInstallSetting's empty-string normalization).
        $this->assertNull($this->store->getInstallSetting('reconcile_lock'));
    }

    public function test_suspects_capped_at_max(): void
    {
        for ($i = 1; $i <= ReconcileEngine::MAX_SUSPECTS + 50; $i++) {
            $this->seedMidgardService($i, runtime: 'rebuilding');
        }

        $stats = (new ReconcileEngine)->run($this->store);

        $this->assertSame(ReconcileEngine::MAX_SUSPECTS, $stats['suspects']);
    }

    public function test_budget_logic_pure(): void
    {
        $engine = new ReconcileEngine;
        $method = new \ReflectionMethod(ReconcileEngine::class, 'budgetLeft');
        $method->setAccessible(true);

        $now = microtime(true);

        $this->assertTrue($method->invoke($engine, $now, 0));
        $this->assertFalse($method->invoke($engine, $now - (ReconcileEngine::TIME_BUDGET_SECONDS + 1), 0));
        $this->assertFalse($method->invoke($engine, $now, ReconcileEngine::CALL_BUDGET));
    }

    public function test_pending_dispatch_count_feeds_the_continuous_drain(): void
    {
        // Fase 4 contract: batch = min(max(count, 10), 100) — the COUNT must
        // cover exactly the rows the flush worker would try: queued, unsent,
        // under the attempt cap.
        $insert = function (string $hash, ?string $sentAt, int $attempts): void {
            Capsule::table('mod_midgard_email_dispatch')->insert([
                'service_id' => 1,
                'dispatch_hash' => $hash,
                'server_uuid' => 'u',
                'queued_at' => date('Y-m-d H:i:s'),
                'sent_at' => $sentAt,
                'queue_attempts' => $attempts,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        };

        $insert('a', null, 0); // eligible
        $insert('b', null, 1); // eligible
        $insert('c', null, 4); // eligible (last attempt)
        $insert('d', null, 5); // capped out
        $insert('e', date('Y-m-d H:i:s'), 0); // already sent

        $this->assertSame(3, $this->store->pendingPasswordDispatchCount());

        // Formula sanity (locked here so it can never regress silently):
        $this->assertSame(10, min(max(3, 10), 100));   // small backlog → legacy 10
        $this->assertSame(37, min(max(37, 10), 100));  // mid → exact drain
        $this->assertSame(100, min(max(300, 10), 100)); // burst → cap
    }
}
