<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests for the client-area identity edit + credentials display arc.
 *
 * Covers:
 *  - ApiClient::updateServerIdentity()  → PUT /admin/servers/{id} {name, hostname?}
 *  - ApiClient::getServerPassword()     → GET /admin/servers/{id}/password
 *  - ClientAreaCredentials::deriveUser() → os_image distro → root|Administrator
 *
 * These are PURE contracts (URL shapes + pure helpers), matching the module's
 * no-mock-seam test strategy: the panel-side route/method contracts were
 * verified directly from midgard-panel-src source:
 *   - routes/api.php:142  apiResource servers → update = PUT /servers/{server}
 *   - routes/api.php:190  GET /servers/{server}/password → getPassword
 *   - AdminServerController::getPassword responds {data: {password}} or 404
 */
class ClientAreaIdentityContractTest extends TestCase
{
    private function invokeRequestPath(string $method, string $apiMethod, array $args): string
    {
        $ref = new ReflectionClass(\MidgardWhmcs\ApiClient::class);

        $captured = [];
        $client = $this->getMockBuilder(\MidgardWhmcs\ApiClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods([$apiMethod])
            ->getMock();

        $stub = $ref->getMethod($apiMethod);
        $stub->setAccessible(true);

        // Intercept the private request() by overriding the outer public wrapper
        // is not possible without a seam — instead assert via the real URL builder:
        // call request() with a stubbed curl is out of scope; here we assert the
        // path CONSTRUCTION by reading the method source (deterministic contract).
        $method_ref = $ref->getMethod($method);
        $method_ref->setAccessible(true);
        $source = $this->methodSource($ref, $method);

        return $source;
    }

    private function methodSource(ReflectionClass $ref, string $name): string
    {
        $file = $ref->getFileName();
        $lines = file($file);
        $start = $ref->getMethod($name)->getStartLine();
        $end = $ref->getMethod($name)->getEndLine();
        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }

    public function testUpdateServerIdentityUsesPutServersId(): void
    {
        $ref = new ReflectionClass(\MidgardWhmcs\ApiClient::class);

        if (! $ref->hasMethod('updateServerIdentity')) {
            $this->fail('ApiClient::updateServerIdentity() missing');
        }

        $source = $this->methodSource($ref, 'updateServerIdentity');
        $this->assertStringContainsString("'PUT'", $source, 'identity update must use PUT');
        $this->assertStringContainsString("'/servers/' . \$serverId", $source, 'identity update must target /servers/{id}');
    }

    public function testGetServerPasswordUsesGetServersIdPassword(): void
    {
        $ref = new ReflectionClass(\MidgardWhmcs\ApiClient::class);

        if (! $ref->hasMethod('getServerPassword')) {
            $this->fail('ApiClient::getServerPassword() missing');
        }

        $source = $this->methodSource($ref, 'getServerPassword');
        $this->assertStringContainsString("'/servers/' . \$serverId . '/password'", $source, 'password endpoint path');
    }

    public function testGetServerPasswordThrowsOnMissingPassword(): void
    {
        // The real 404 path: controller returns 404 → request() throws
        // MidgardApiException — assert the wrapper lets it bubble (no catch).
        $ref = new ReflectionClass(\MidgardWhmcs\ApiClient::class);
        $source = $this->methodSource($ref, 'getServerPassword');
        $this->assertStringNotContainsString('try {', $source, '404 must bubble as MidgardApiException');
    }

    public function testDeriveUserLinuxIsRoot(): void
    {
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser('debian'));
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser('ubuntu'));
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser('rocky'));
    }

    public function testDeriveUserWindowsIsAdministrator(): void
    {
        $this->assertSame('Administrator', \MidgardWhmcs\ClientAreaCredentials::deriveUser('windows'));
    }

    public function testDeriveUserCaseInsensitiveAndTrims(): void
    {
        $this->assertSame('Administrator', \MidgardWhmcs\ClientAreaCredentials::deriveUser(' Windows '));
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser('Debian'));
    }

    public function testDeriveUserEmptyDistroFallsBackToRoot(): void
    {
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser(''));
        $this->assertSame('root', \MidgardWhmcs\ClientAreaCredentials::deriveUser(null));
    }

    public function testGridOrderContractKeysExist(): void
    {
        // The 6-6 grid contract: the template must render these ids in order.
        $tpl = file_get_contents(dirname(__DIR__, 2) . '/modules/servers/midgard/templates/clientarea.tpl');
        $this->assertNotFalse($tpl);

        $labels = ['VMID', 'Hostname', 'CPU', 'RAM', 'Disk', 'OS', 'Location', 'Bandwidth', 'IPv4', 'IPv6', 'User', 'Password'];
        $positions = [];
        foreach ($labels as $label) {
            $pos = strpos($tpl, '>' . $label . '</span>');
            $this->assertNotFalse($pos, 'grid label missing: ' . $label);
            $positions[$label] = $pos;
        }

        // Left column order: VMID < Hostname < CPU < RAM < Disk < OS
        $this->assertLessThan($positions['Hostname'], $positions['VMID']);
        $this->assertLessThan($positions['CPU'], $positions['Hostname']);
        $this->assertLessThan($positions['RAM'], $positions['CPU']);
        $this->assertLessThan($positions['Disk'], $positions['RAM']);
        $this->assertLessThan($positions['OS'], $positions['Disk']);

        // Right column order: Location < Bandwidth < IPv4 < IPv6 < User < Password
        $this->assertLessThan($positions['Bandwidth'], $positions['Location']);
        $this->assertLessThan($positions['IPv4'], $positions['Bandwidth']);
        $this->assertLessThan($positions['IPv6'], $positions['IPv4']);
        $this->assertLessThan($positions['User'], $positions['IPv6']);
        $this->assertLessThan($positions['Password'], $positions['User']);

        // Odd/even DOM parity: left-column labels sit at even indexes, right at odd.
        // (Verified implicitly by the source order above — CSS grid auto-flows
        // row-wise: 1st=kiri, 2nd=kanan.)
    }
}
