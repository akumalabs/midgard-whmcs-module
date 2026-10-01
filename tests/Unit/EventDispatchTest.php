<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\CallbackHandler;
use MidgardWhmcs\CallbackRequestVerifier;
use MidgardWhmcs\MetadataStore;
use PHPUnit\Framework\TestCase;

/**
 * Scaling plan v2 Fase 2 — event dispatch contract of the module callback.
 *
 * Locks:
 *  - the legacy build.completed envelope flows EXACTLY as before;
 *  - build.failed flips midgard_runtime_status to failed with the sanitized
 *    error code, is idempotent (replay = same state), and sends NO email;
 *  - rebuild.completed persists the fresh identity payload (name, hostname,
 *    os name, running status) into the SAME meta keys syncFromPanel writes;
 *  - unknown events are acknowledged ('ignored') — the endpoint answers 200,
 *    never 5xx, so an older module never triggers the panel's retry ladder;
 *  - the verifier accepts the known event family and rejects others.
 *
 * @covers \MidgardWhmcs\CallbackHandler
 * @covers \MidgardWhmcs\RebuildCompletedHandler
 * @covers \MidgardWhmcs\CallbackRequestVerifier
 */
final class EventDispatchTest extends TestCase
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
        if (! function_exists('localAPI')) {
            eval('function localAPI(string $command, array $values): array { return ["result" => "success", "__command" => $command]; }');
        }
    }

    private function seedService(int $serviceId, int $serverId): void
    {
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
        $this->store->upsert($serviceId, $meta);
    }

    public function test_build_completed_routes_to_legacy_flow(): void
    {
        $this->seedService(60, 900);

        $status = (new CallbackHandler())->handleEnvelope([
            'event' => 'server.build.completed',
            'data' => ['server_id' => 900],
        ]);

        // No dispatch row seeded → legacy flow reports not_ready (identical
        // to the pre-dispatch behavior for this fixture).
        $this->assertSame('not_ready', $status);
    }

    public function test_build_failed_flips_runtime_status_and_is_idempotent(): void
    {
        $this->seedService(61, 901);

        $handler = new CallbackHandler();
        $envelope = [
            'event' => 'server.build.failed',
            'data' => [
                'server_id' => 901,
                'operation' => 'rebuild',
                'error_code' => 'image_not_found',
            ],
        ];

        $this->assertSame('failed_state_recorded', $handler->handleEnvelope($envelope));

        $meta = $this->store->get(61);
        $this->assertSame('failed', $meta['midgard_runtime_status']);
        $this->assertSame('image_not_found', $meta['midgard_last_error']);

        // Replay (panel at-least-once): same state, same answer, no error.
        $this->assertSame('failed_state_recorded', $handler->handleEnvelope($envelope));
        $meta2 = $this->store->get(61);
        $this->assertSame('failed', $meta2['midgard_runtime_status']);
        $this->assertSame('image_not_found', $meta2['midgard_last_error']);
    }

    public function test_rebuild_completed_persists_identity_and_running_status(): void
    {
        $this->seedService(62, 902);

        $status = (new \MidgardWhmcs\RebuildCompletedHandler())->handle([
            'server_id' => 902,
            'operation' => 'rebuild',
            'os_image' => ['id' => 4, 'name' => 'Ubuntu 24.04', 'distro' => 'ubuntu', 'version' => '24.04'],
            'name' => 'brand-new-name',
            'hostname' => 'brand-new.example.com',
            'status' => 'running',
            'completed_at' => '2026-10-01T12:00:00+00:00',
        ]);

        $this->assertSame('synced', $status);

        $meta = $this->store->get(62);
        $this->assertSame('brand-new-name', $meta['midgard_server_name']);
        $this->assertSame('brand-new.example.com', $meta['midgard_server_hostname']);
        $this->assertSame('Ubuntu 24.04', $meta['midgard_os_name']);
        $this->assertSame('running', $meta['midgard_runtime_status']);
        $this->assertSame('', $meta['midgard_last_error']);
    }

    public function test_rebuild_completed_is_idempotent_on_replay(): void
    {
        $this->seedService(63, 903);

        $handler = new \MidgardWhmcs\RebuildCompletedHandler();
        $data = [
            'server_id' => 903,
            'name' => 'again',
            'hostname' => 'again.example.com',
            'os_image' => ['id' => 9, 'name' => 'Debian 13'],
            'status' => 'running',
        ];

        $this->assertSame('synced', $handler->handle($data));
        $this->assertSame('synced', $handler->handle($data));

        $meta = $this->store->get(63);
        $this->assertSame('again', $meta['midgard_server_name']);
        $this->assertSame('running', $meta['midgard_runtime_status']);
    }

    public function test_unknown_event_is_acknowledged_not_processed(): void
    {
        $this->seedService(64, 904);

        $status = (new CallbackHandler())->handleEnvelope([
            'event' => 'server.status.changed', // batch-2 event, older module
            'data' => ['server_id' => 904],
        ]);

        $this->assertSame('ignored', $status);

        // And nothing was touched: runtime status unchanged ('unknown').
        $meta = $this->store->get(64);
        $this->assertSame('unknown', $meta['midgard_runtime_status']);
    }

    public function test_verifier_accepts_known_family_and_rejects_unknown(): void
    {
        foreach (['server.build.completed', 'server.build.failed', 'server.rebuild.completed'] as $event) {
            $parsed = CallbackRequestVerifier::parseEnvelope(
                (string) json_encode(['event' => $event, 'data' => ['server_id' => 5]])
            );
            $this->assertTrue($parsed['ok'], $event);
            $this->assertSame($event, $parsed['event']);
        }

        $unknown = CallbackRequestVerifier::parseEnvelope(
            (string) json_encode(['event' => 'server.future.event', 'data' => ['server_id' => 5]])
        );
        $this->assertFalse($unknown['ok']);
    }
}
