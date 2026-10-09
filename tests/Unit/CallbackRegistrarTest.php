<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\ApiClient;
use MidgardWhmcs\CallbackRegistrar;
use MidgardWhmcs\CallbackRequestVerifier;
use MidgardWhmcs\MetadataStore;
use MidgardWhmcs\SecretCrypto;
use PHPUnit\Framework\TestCase;

/**
 * Idempotent webhook registration (f2-c2 contract): secret generated once
 * and persisted BEFORE the HTTP call; "registered" flag written only after
 * a successful POST; failures keep the flag unset so the next call retries
 * with the SAME secret.
 *
 * @covers \MidgardWhmcs\CallbackRegistrar
 */
final class CallbackRegistrarTest extends TestCase
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

    /** @return array<object{url: string, secret: string}> */
    private function fakeFactory(array &$recorded): callable
    {
        return static function (array $params) use (&$recorded) {
            return new class($recorded) {
                public function __construct(private array &$recordedRef) {}

                public function registerWebhook(string $url, string $secret): array
                {
                    $this->recordedRef[] = ['url' => $url, 'secret' => $secret];
                    return ['ok' => true];
                }
            };
        };
    }

    private function params(): array
    {
        return [
            'serverhostname' => 'panel.example.com',
            'serveraccesshash' => 'tok',
            'systemurl' => 'https://whmcs.example.com',
        ];
    }

    public function test_registers_once_then_skips_on_subsequent_calls(): void
    {
        $recorded = [];
        $registrar = new CallbackRegistrar();

        $first = $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));
        $this->assertSame('registered', $first['status']);

        $second = $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));
        $this->assertSame('skipped', $second['status']);

        $this->assertCount(1, $recorded, 'the webhook POST must fire exactly once');
        $this->assertSame(
            'https://whmcs.example.com/modules/servers/midgard/callback.php',
            $recorded[0]['url']
        );
    }

    public function test_secret_is_generated_once_and_reused_across_attempts(): void
    {
        $recorded = [];
        $registrar = new CallbackRegistrar();

        $calls = 0;
        $failingFactory = static function (array $params) use (&$recorded, &$calls) {
            return new class($recorded, $calls) {
                public function __construct(private array &$recordedRef, private int &$callsRef) {}

                public function registerWebhook(string $url, string $secret): array
                {
                    $this->callsRef++;
                    $this->recordedRef[] = ['url' => $url, 'secret' => $secret];
                    throw new \RuntimeException('panel down');
                }
            };
        };

        try {
            $registrar->register($this->params(), $this->store, $failingFactory);
            $this->fail('expected the panel failure to bubble up');
        } catch (\RuntimeException $e) {
            $this->assertSame('panel down', $e->getMessage());
        }

        // Not registered → next call retries.
        $flag = $this->store->getInstallSetting(CallbackRegistrar::SETTING_REGISTERED);
        $this->assertNull($flag, 'failed registration must NOT set the flag');

        // ...with the SAME persisted secret (generated before the HTTP call).
        $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));
        $this->assertCount(2, $recorded);
        $this->assertSame($recorded[0]['secret'], $recorded[1]['secret']);
        $this->assertSame(2, $this->store->getInstallSetting(CallbackRegistrar::SETTING_REGISTERED) !== null ? 2 : 0);
    }

    public function test_secret_is_64_hex_chars(): void
    {
        $secret = CallbackRegistrar::generateSecret();

        $this->assertSame(64, strlen($secret));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
    }

    public function test_persisted_secret_matches_sent_secret(): void
    {
        $recorded = [];
        $registrar = new CallbackRegistrar();
        $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));

        // At rest the secret MUST be WHMCS-encrypted ('enc:' blob, SecretCrypto):
        // the raw 64-hex value never sits in the install KV table.
        $rawStored = (string) $this->store->getInstallSetting(CallbackRegistrar::SETTING_SECRET);
        $this->assertNotSame($recorded[0]['secret'], $rawStored, 'raw secret must NOT be persisted');
        $this->assertStringStartsWith('enc:', $rawStored);

        // Read side (unseal) restores exactly the secret that was sent.
        $this->assertSame(
            $recorded[0]['secret'],
            SecretCrypto::unseal($rawStored)
        );
    }

    /**
     * End-to-end verification contract: a callback signed with the secret the
     * registrar sent must still VERIFY when the verifier reads the secret back
     * through SecretCrypto::unseal() (what callback.php does) — i.e.
     * encrypt-at-rest must not change the HMAC key material.
     */
    public function test_callback_verification_passes_with_encrypted_secret_at_rest(): void
    {
        $recorded = [];
        (new CallbackRegistrar())->register($this->params(), $this->store, $this->fakeFactory($recorded));
        $sentSecret = $recorded[0]['secret'];

        $stored = (string) $this->store->getInstallSetting(CallbackRegistrar::SETTING_SECRET);
        $this->assertNotSame($sentSecret, $stored);

        $body = '{"event":"server.build.completed","data":{"server_id":1}}';
        $timestamp = '1750000100';
        $headers = [
            'X-Midgard-Event' => 'server.build.completed',
            'X-Midgard-Timestamp' => $timestamp,
            'X-Midgard-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $sentSecret),
        ];

        $secret = SecretCrypto::unseal($stored);
        $verification = CallbackRequestVerifier::verify($secret, $headers, $body, (int) $timestamp);
        $this->assertTrue($verification['ok'], $verification['error']);
    }

    /**
     * Legacy installs hold the RAW secret (pre-encryption format). unseal()
     * must pass it through unchanged — the stale-flag re-registration path
     * re-sends the SAME raw secret to the panel instead of minting a new one.
     */
    public function test_legacy_raw_secret_is_reused_not_reencrypted(): void
    {
        $this->store->setInstallSetting(CallbackRegistrar::SETTING_SECRET, 'aabb');
        $this->store->setInstallSetting(CallbackRegistrar::SETTING_REGISTERED, '2026-09-16 16:58:31');

        $recorded = [];
        (new CallbackRegistrar())->register($this->params(), $this->store, $this->fakeFactory($recorded));

        $this->assertSame('aabb', SecretCrypto::unseal(
            (string) $this->store->getInstallSetting(CallbackRegistrar::SETTING_SECRET)
        ));
        $this->assertSame('aabb', $recorded[0]['secret']);
    }

    public function test_reseller_mode_registers_with_scoped_client(): void
    {
        // The registrar's default factory must pick up the reseller base
        // path — asserted through Config::basePath on the same params, which
        // is what CallbackRegistrar::defaultClient() feeds into ApiClient.
        $params = [
            'serverhostname' => 'panel.example.com',
            'serveraccesshash' => 'reseller|res-tok',
            'systemurl' => 'https://whmcs.example.com',
        ];

        $this->assertSame(ApiClient::DEFAULT_BASE_PATH, '/api/v1/admin');
        $this->assertSame(
            '/api/v1/reseller',
            \MidgardWhmcs\Config::basePath($params)
        );

        $recorded = [];
        (new CallbackRegistrar())->register($params, $this->store, $this->fakeFactory($recorded));
        $this->assertSame('registered', $this->store->getInstallSetting(CallbackRegistrar::SETTING_REGISTERED) !== null ? 'registered' : 'none');
        $this->assertCount(1, $recorded);
    }

    public function test_unresolvable_system_url_throws_and_keeps_flag_unset(): void
    {
        // No systemurl, no WHMCS constant, no tblservers row, no $_SERVER.
        $params = [
            'serverhostname' => 'panel.example.com',
            'serveraccesshash' => 'tok',
        ];

        try {
            $noop = [];
            (new CallbackRegistrar())->register($params, $this->store, $this->fakeFactory($noop));
            $this->fail('expected URL resolution failure');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('System URL', $e->getMessage());
        }

        $this->assertNull($this->store->getInstallSetting(CallbackRegistrar::SETTING_REGISTERED));
    }

    public function test_callback_url_appends_module_path(): void
    {
        $this->assertSame(
            'https://whmcs.example.com/modules/servers/midgard/callback.php',
            CallbackRegistrar::callbackUrl(['systemurl' => 'https://whmcs.example.com/'])
        );
    }

    /**
     * Callback deliveries MUST be https: installs behind a reverse proxy
     * (Cloudflare/nginx) 301 http→https, and the followed redirect degrades
     * the signed POST to a GET the endpoint can never verify (observed in
     * production 2026-09-16: http URL registered → panel push trapped in a
     * 301 chain).
     */
    public function test_callback_url_forces_https(): void
    {
        $this->assertSame(
            'https://whmcs.example.com/modules/servers/midgard/callback.php',
            CallbackRegistrar::callbackUrl(['systemurl' => 'http://whmcs.example.com'])
        );
    }

    /**
     * The flag stores the REGISTERED URL, so a legacy install whose flag is
     * a timestamp (or a URL that no longer matches — scheme change, domain
     * change) is treated as stale and re-registers with the SAME secret.
     * This is the self-healing path for the production http registration.
     */
    public function test_stale_registered_url_reregisters_with_same_secret(): void
    {
        // Simulate a legacy flag written by the old code (a timestamp).
        $this->store->setInstallSetting(CallbackRegistrar::SETTING_SECRET, 'aabb'); // short legacy secret
        $this->store->setInstallSetting(CallbackRegistrar::SETTING_REGISTERED, '2026-09-16 16:58:31');

        $recorded = [];
        (new CallbackRegistrar())->register($this->params(), $this->store, $this->fakeFactory($recorded));

        $this->assertCount(1, $recorded);
        // SAME secret must be reused (the panel row already knows it).
        $this->assertSame('aabb', $recorded[0]['secret']);
        // ...and the flag now holds the freshly registered https URL.
        $this->assertSame(
            'https://whmcs.example.com/modules/servers/midgard/callback.php',
            $this->store->getInstallSetting(CallbackRegistrar::SETTING_REGISTERED)
        );
    }

    /**
     * Happy-path idempotency must be unchanged: same URL twice → one HTTP
     * registration total (second call skips).
     */
    public function test_same_url_registered_twice_skips_second(): void
    {
        $recorded = [];
        $registrar = new CallbackRegistrar();
        $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));
        $registrar->register($this->params(), $this->store, $this->fakeFactory($recorded));

        $this->assertCount(1, $recorded);
    }
}
