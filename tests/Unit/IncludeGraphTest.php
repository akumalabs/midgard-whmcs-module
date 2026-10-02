<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Include-graph contract (audit checklist #13).
 *
 * Every lib/*.php class MUST be eagerly required by midgard.php's require
 * block: the hooks/callback/ajax entry points all load midgard.php, but the
 * unit tests require classes explicitly and never boot the WHMCS include
 * graph — so a class loaded lazily at one call site ships a fatal
 * "Class not found" to every OTHER entry point, where catch-alls turn it
 * into a silent no-op. This exact failure shipped 2026-10-02: the
 * ClientAreaPageProductDetails hook referenced ClientAreaDisplay which was
 * never required — prod silently kept the native IP rows visible through
 * TWO releases while the suite stayed green.
 *
 * The graph test below fails the moment a new lib file is not required;
 * the load test proves the real entry file (hooks.php) makes the class
 * available in-process.
 *
 * @covers \MidgardWhmcs\ClientAreaDisplay
 */
final class IncludeGraphTest extends TestCase
{
    private const MODULE_DIR = __DIR__ . '/../../modules/servers/midgard';

    public function test_hooks_entry_point_loads_every_lib_class(): void
    {
        if (! defined('WHMCS')) {
            define('WHMCS', 'unit-test'); // midgard.php/hooks.php entry guards.
        }

        require_once self::MODULE_DIR . '/hooks.php';

        // The class whose missing require silently broke prod:
        $this->assertTrue(
            class_exists(\MidgardWhmcs\ClientAreaDisplay::class),
            'hooks.php (via midgard.php) must eagerly load ClientAreaDisplay'
        );

        // Spot-check the rest of the graph so the entry file is proven live,
        // not just the file list:
        foreach (['SyncService', 'MetadataStore', 'PasswordMailer', 'ReconcileEngine', 'CallbackHandler'] as $cls) {
            $this->assertTrue(
                class_exists("MidgardWhmcs\\{$cls}"),
                "hooks.php must eagerly load {$cls}"
            );
        }
    }

    public function test_every_lib_file_is_eagerly_required_by_midgard_php(): void
    {
        $libFiles = glob(self::MODULE_DIR . '/lib/*.php');
        $this->assertNotEmpty($libFiles, 'lib/ must contain classes');

        $source = (string) file_get_contents(self::MODULE_DIR . '/midgard.php');
        preg_match_all('/require_once __DIR__ \. \x27\/lib\/([A-Za-z0-9_]+)\.php\x27/', $source, $m);
        $required = array_flip($m[1]);

        $missing = [];
        foreach ($libFiles as $path) {
            $name = basename($path, '.php');
            if (! isset($required[$name])) {
                $missing[] = $name;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'every lib/*.php must appear in midgard.php require block (checklist #13): ' . implode(', ', $missing)
        );
    }
}
