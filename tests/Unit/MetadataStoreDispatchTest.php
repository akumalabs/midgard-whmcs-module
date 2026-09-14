<?php

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\PasswordMailer;
use PHPUnit\Framework\TestCase;

/**
 * Real-sqlite tests for the password dispatch claim lifecycle. The duplicate
 * claim path used to return null and silently strand rows (credentials email
 * never dispatched — production incident 2026-09-11).
 *
 * @covers \MidgardWhmcs\MetadataStore::claimPasswordDispatch
 */
class MetadataStoreDispatchTest extends TestCase
{
    private MetadataStore $store;

    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        // Reset the per-process schema flag so ensureSchema() runs against
        // this fresh in-memory database.
        $ref = new \ReflectionProperty(MetadataStore::class, 'schemaReady');
        $ref->setAccessible(true);
        $ref->setValue(null, false);

        $this->store = new MetadataStore();
        $this->store->get(1); // triggers ensureSchema()
    }

    public function test_duplicate_claim_recycles_stuck_row_instead_of_aborting(): void
    {
        $hash1 = $this->store->claimPasswordDispatch(7, 'uuid-7');
        $this->assertNotNull($hash1);

        // Simulate the production incident: row exists, never sent, never
        // queued (claim happened but queueing was interrupted).
        $this->store->queuePasswordDispatch($hash1);
        Capsule::table('mod_midgard_email_dispatch')
            ->where('dispatch_hash', $hash1)
            ->update(['queued_at' => null, 'queue_attempts' => 3, 'last_error' => 'old failure']);

        // A second claim must NOT return null (the old bug) — it recycles.
        $hash2 = $this->store->claimPasswordDispatch(7, 'uuid-7');
        $this->assertSame($hash1, $hash2);

        $row = (array) Capsule::table('mod_midgard_email_dispatch')->where('dispatch_hash', $hash1)->first();
        $this->assertNull($row['sent_at']);
        $this->assertNull($row['queued_at'], 'recycled row is re-armed for queueing');
        $this->assertSame(0, (int) $row['queue_attempts']);
        $this->assertNull($row['last_error']);
    }

    public function test_claim_after_send_rearms_for_a_new_email(): void
    {
        $hash1 = $this->store->claimPasswordDispatch(8, 'uuid-8');
        $this->store->queuePasswordDispatch($hash1);
        $this->store->finalizePasswordDispatch(8, $hash1);

        $hash2 = $this->store->claimPasswordDispatch(8, 'uuid-8');
        $this->assertSame($hash1, $hash2, 'same dispatch key re-arms the same row');

        $row = (array) Capsule::table('mod_midgard_email_dispatch')->where('dispatch_hash', $hash1)->first();
        $this->assertNull($row['sent_at'], 'sent marker cleared so a new email can go out');
        $this->assertNull($row['queued_at']);
    }

    public function test_claim_while_queued_and_unsent_keeps_worker_state(): void
    {
        $hash1 = $this->store->claimPasswordDispatch(9, 'uuid-9');
        $this->store->queuePasswordDispatch($hash1);

        $hash2 = $this->store->claimPasswordDispatch(9, 'uuid-9');
        $this->assertSame($hash1, $hash2);

        $row = (array) Capsule::table('mod_midgard_email_dispatch')->where('dispatch_hash', $hash1)->first();
        $this->assertNotNull($row['queued_at'], 'worker-owned queued state is preserved');
        $this->assertNull($row['sent_at']);
    }

    public function test_sealed_password_survives_read_modify_write(): void
    {
        // Regression for the 2026-09-14 incident: queue() sealed the password
        // into $meta but upsert() had no midgard_pending_password column in its
        // payload, so the blob was silently dropped and the cron worker found
        // nothing to unseal (attempts burned, last_error never set).
        $this->store->upsert(21, [
            'midgard_server_id' => '42',
            'midgard_pending_password' => 'plain:U2VjcmV0UGFzcw==',
        ]);

        $meta = $this->store->get(21);
        $this->assertSame('plain:U2VjcmV0UGFzcw==', $meta['midgard_pending_password']);

        // A later full-meta rewrite (sync path) must preserve the blob.
        $meta['midgard_server_id'] = '43';
        $this->store->upsert(21, $meta);
        $this->assertSame('plain:U2VjcmV0UGFzcw==', $this->store->get(21)['midgard_pending_password']);
    }

    public function test_queue_persists_sealed_password_for_cron_worker(): void
    {
        $params = ['serviceid' => 22, 'userid' => 5];

        PasswordMailer::queue($params, $this->store, 'uuid-22', 'Str0ng!Passw0rd!');

        $blob = (string) $this->store->get(22)['midgard_pending_password'];
        $this->assertNotSame('', $blob, 'sealed password must persist past queue()');
        $this->assertTrue(
            str_starts_with($blob, 'enc:') || str_starts_with($blob, 'plain:'),
            "unexpected seal format: {$blob}"
        );

        $pending = Capsule::table('mod_midgard_email_dispatch')
            ->where('service_id', 22)
            ->whereNotNull('queued_at')
            ->count();
        $this->assertSame(1, $pending);
    }
}
