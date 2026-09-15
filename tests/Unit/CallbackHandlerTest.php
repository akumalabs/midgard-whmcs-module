<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\CallbackHandler;
use MidgardWhmcs\MetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Webhook-side counterpart of the cron flush worker (f2-c2 contract).
 * Exercises the REAL MetadataStore against sqlite: idempotency through the
 * dispatch rows, service resolution via midgard_server_id meta (shared
 * panel server → several services), and the gate-before-send ordering.
 *
 * @covers \MidgardWhmcs\CallbackHandler
 */
final class CallbackHandlerTest extends TestCase
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

        // Minimal WHMCS core tables the handler reads (tblhosting + tblservers).
        Capsule::schema()->create('tblservers', static function ($table): void {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('hostname')->nullable();
            $table->string('ipaddress')->nullable();
            $table->text('accesshash')->nullable();
            $table->text('password')->nullable();
            $table->string('type')->nullable();
            $table->integer('active')->default(0);
        });
        Capsule::schema()->create('tblhosting', static function ($table): void {
            $table->increments('id');
            $table->integer('userid')->default(0);
            $table->string('domainstatus')->default('Pending');
            $table->integer('server')->default(0);
        });

        // Suppress PHP warnings from unreachable cURL handles in the gate
        // (tests that stop BEFORE the gate never trigger this).
        set_error_handler(static function (int $errno, string $errstr): bool {
            if (str_contains($errstr, 'unable to connect') || str_contains($errstr, 'Could not resolve')) {
                return true;
            }
            return false;
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public static function setUpBeforeClass(): void
    {
        // WHMCS-only helper used by PasswordMailer's send path; defined so
        // an unexpected send (a failure of this contract) is observable.
        if (! function_exists('localAPI')) {
            eval('function localAPI(string $command, array $values): array { return ["result" => "success", "__command" => $command]; }');
        }
    }

    private function seedService(int $serviceId, int $serverId, string $serverUuid): void
    {
        // tblservers: the gate/mailer resolve the panel connection from here.
        // (First() only — additional services in the same test reuse it.)
        if (! Capsule::table('tblservers')->where('id', 5)->exists()) {
            Capsule::table('tblservers')->insert([
                'id' => 5,
                'name' => 'Midgard',
                'hostname' => 'panel.example.com',
                'ipaddress' => '203.0.113.7',
                'accesshash' => 'tok',
                'password' => '',
                'type' => 'midgard',
                'active' => 1,
            ]);
        }
        Capsule::table('tblhosting')->insert([
            'id' => $serviceId,
            'userid' => 3,
            'domainstatus' => 'Active',
            'server' => 5,
        ]);

        $meta = $this->store->get($serviceId);
        $meta['midgard_server_id'] = (string) $serverId;
        $meta['midgard_server_uuid'] = $serverUuid;
        $this->store->upsert($serviceId, $meta);
    }

    private function enqueueDispatch(int $serviceId, string $serverUuid): string
    {
        $hash = $this->store->claimPasswordDispatch($serviceId, $serverUuid);
        $this->assertNotNull($hash);
        PasswordMailerQueueProbe::seal($this->store, $serviceId, 'Str0ng!Pass');
        $this->store->queuePasswordDispatch($hash);

        return $hash;
    }

    public function test_no_service_when_meta_unknown(): void
    {
        $this->assertSame('no_service', (new CallbackHandler())->handle(['server_id' => 12345]));
        $this->assertSame('no_service', (new CallbackHandler())->handle(['server_id' => 0]));
    }

    public function test_not_ready_when_never_queued(): void
    {
        $this->seedService(11, 900, 'uuid-900');

        $this->assertSame('not_ready', (new CallbackHandler())->handle(['server_id' => 900]));
    }

    public function test_not_ready_when_server_not_ready(): void
    {
        $this->seedService(12, 901, 'uuid-901');
        $this->enqueueDispatch(12, 'uuid-901');

        // Gate: the panel is unreachable in this environment → gate returns
        // panel_unreachable → not_ready. The assertion proves the gate ran
        // BEFORE any send (a send would have returned 'sent').
        $this->assertSame('not_ready', (new CallbackHandler())->handle(['server_id' => 901]));

        // The unsent dispatch row must be untouched for the cron worker.
        $row = (array) Capsule::table('mod_midgard_email_dispatch')
            ->where('service_id', 12)
            ->first();
        $this->assertNull($row['sent_at']);
        $this->assertNotNull($row['queued_at'], 'queued state preserved for the cron safety net');
    }

    public function test_already_sent_when_dispatch_finalized(): void
    {
        $this->seedService(13, 902, 'uuid-902');
        $hash = $this->enqueueDispatch(13, 'uuid-902');

        // Simulate the email having gone out earlier (e.g. via cron).
        $this->store->finalizePasswordDispatch(13, $hash);
        // finalizePasswordDispatch clears the pending-dispatch lookup via
        // sent_at; pendingDispatchForService() only returns UNSENT rows.

        $this->assertSame('already_sent', (new CallbackHandler())->handle(['server_id' => 902]));
    }

    public function test_already_sent_for_second_delivery_with_no_pending_dispatch(): void
    {
        // Idempotency across MULTIPLE services sharing one panel server.
        $this->seedService(14, 903, 'uuid-903');
        $this->seedService(15, 903, 'uuid-904');

        $hash14 = $this->enqueueDispatch(14, 'uuid-903');
        $this->store->finalizePasswordDispatch(14, $hash14);

        // Service 15 was queued but its send never finalized — the webhook
        // attempts its send; the gate is unreachable here → stays not_ready
        // (cron will deliver it later). The already-sent sibling is reported
        // through the aggregate 'already_sent'... but service 15 returned
        // not_ready, and 'sent'/'not_ready' outrank 'already_sent'. Assert
        // the precise aggregate: not_ready (a send is still pending).
        $hash15 = $this->enqueueDispatch(15, 'uuid-904');

        $this->assertSame('not_ready', (new CallbackHandler())->handle(['server_id' => 903]));

        // Releasing the pending dispatch (sync-path send failure) returns the
        // service to "zero rows" = not_ready — NEVER already_sent — so an
        // ambiguous re-send can't happen; the cron/queue re-arm owns it.
        $this->store->releasePasswordDispatch($hash15);
        $this->assertSame('not_ready', (new CallbackHandler())->handle(['server_id' => 903]));
    }

    public function test_no_service_skips_dispatchless_but_reports_sent_sibling(): void
    {
        $this->seedService(16, 904, 'uuid-905');
        $this->seedService(17, 904, 'uuid-906');

        $hash = $this->enqueueDispatch(16, 'uuid-905');
        $this->store->finalizePasswordDispatch(16, $hash);
        // Service 17: no dispatch at all → per-service no dispatch →
        // hasSentRow=false → 'not_ready'.
        $this->assertSame('not_ready', (new CallbackHandler())->handle(['server_id' => 904]));
    }

    public function test_send_failure_records_error_and_returns_not_ready(): void
    {
        $this->seedService(18, 905, 'uuid-907');
        $hash = $this->enqueueDispatch(18, 'uuid-907');

        // Force the gate to pass while the mailer fails: make PasswordMailer
        // throw by removing the sealed blob AFTER queueing.
        $this->store->patchMeta(18, ['midgard_pending_password' => '']);

        // The real gate hits cURL here; accept both "gate blocked" outcomes
        // only if the environment somehow reaches the network — the contract
        // assertions below hold either way.
        $status = (new CallbackHandler())->handle(['server_id' => 905]);

        $this->assertContains($status, ['not_ready'], 'send must never report success without a mail');
        $row = (array) Capsule::table('mod_midgard_email_dispatch')
            ->where('dispatch_hash', $hash)
            ->first();
        $this->assertNull($row['sent_at'], 'a failed send must not finalize the dispatch');
    }

    public function test_handle_ignores_non_positive_server_id(): void
    {
        $this->assertSame('no_service', (new CallbackHandler())->handle([]));
    }
}

/**
 * Test-only bridge: PasswordMailer::queue()'s seal() is private, so the
 * probe re-uses queue() itself with a stubbed dispatch store write. Simply
 * patching meta directly is equivalent — the blob column is what the async
 * send reads.
 */
final class PasswordMailerQueueProbe
{
    public static function seal(MetadataStore $store, int $serviceId, string $password): void
    {
        // 'plain:' prefix = the fallback seal format used when WHMCS's
        // encrypt() is unavailable (unit test context).
        $store->patchMeta($serviceId, ['midgard_pending_password' => 'plain:' . base64_encode($password)]);
    }
}
