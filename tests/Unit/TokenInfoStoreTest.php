<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\TokenInfoStore;
use PHPUnit\Framework\TestCase;

/**
 * Connection bootstrap (reseller Fase B): GET /api/v1/token-info is called
 * at TestConnection, cached install-wide, and EVERY ApiClient base path
 * resolution (TokenInfoStore::resolveBasePath) prefers: explicit
 * "reseller|" prefix → cached discovery → Config default.
 *
 * @covers \MidgardWhmcs\TokenInfoStore
 */
final class TokenInfoStoreTest extends TestCase
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

    private function paramsWith(string $accessHash): array
    {
        return [
            'serverhostname' => 'panel.example.com',
            'serveraccesshash' => $accessHash,
        ];
    }

    public function test_resolve_without_cache_and_without_prefix_falls_back_to_admin(): void
    {
        // Fresh install, no discovery yet: legacy behaviour byte-for-byte.
        $this->assertSame(
            '/api/v1/admin',
            TokenInfoStore::resolveBasePath($this->paramsWith('plain-token'))
        );
    }

    public function test_explicit_prefix_overrides_cached_discovery(): void
    {
        $this->store->setInstallSetting(
            'connection_token_info',
            (string) json_encode(['token_type' => 'admin', 'api_base_path' => '/api/v1/admin'])
        );

        // Operator pins reseller explicitly → never second-guessed.
        $this->assertSame(
            '/api/v1/reseller',
            TokenInfoStore::resolveBasePath($this->paramsWith('reseller|plain-token'))
        );
    }

    public function test_cached_discovery_switches_plain_token_to_reseller_path(): void
    {
        $this->store->setInstallSetting(
            'connection_token_info',
            (string) json_encode(['token_type' => 'reseller', 'api_base_path' => '/api/v1/reseller'])
        );

        $this->assertSame(
            '/api/v1/reseller',
            TokenInfoStore::resolveBasePath($this->paramsWith('plain-token'))
        );
    }

    public function test_instance_info_reads_cache(): void
    {
        $this->assertNull((new TokenInfoStore($this->store))->info());

        $this->store->setInstallSetting(
            'connection_token_info',
            (string) json_encode(['token_type' => 'reseller', 'api_base_path' => '/api/v1/reseller', 'owner_name' => 'InstantRDP'])
        );

        $info = (new TokenInfoStore($this->store))->info();
        $this->assertNotNull($info);
        $this->assertSame('reseller', $info['token_type']);
        $this->assertSame('InstantRDP', $info['owner_name']);
    }

    public function test_corrupted_cache_entry_is_ignored(): void
    {
        $this->store->setInstallSetting('connection_token_info', 'not-json{{{');

        $this->assertNull((new TokenInfoStore($this->store))->info());
        $this->assertSame(
            '/api/v1/admin',
            TokenInfoStore::resolveBasePath($this->paramsWith('plain-token'))
        );
    }

    public function test_discover_swallows_network_failure_and_keeps_legacy_path(): void
    {
        // Unreachable host — discover() must not throw; nothing cached.
        $store = new TokenInfoStore($this->store);
        $store->discover($this->paramsWith('plain-token'));

        $this->assertNull($store->info());
        $this->assertSame(
            '/api/v1/admin',
            TokenInfoStore::resolveBasePath($this->paramsWith('plain-token'))
        );
    }
}
