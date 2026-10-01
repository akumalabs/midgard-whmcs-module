<?php

declare(strict_types=1);

namespace MidgardWhmcs\Tests\Unit;

use Illuminate\Database\Capsule\Manager as Capsule;
use MidgardWhmcs\ClientAreaDisplay;
use PHPUnit\Framework\TestCase;

/**
 * Client area polish: native "Dedicated IP" / "Assigned IPs" display rows
 * must disappear from the client area product details page for midgard
 * products (the module template already renders live IPv4/IPv6 in its spec
 * grid), while NON-midgard products keep WHMCS's default behavior untouched.
 * The DB columns themselves are never modified — admin visibility and
 * search-by-IP depend on them.
 *
 * CONTRACT: ClientAreaPage* hook responses are MERGED into the template
 * vars. So the filter must return OVERRIDES ('' for each hidden key) —
 * an unset() would be a silent no-op (live-verified 2026-10-02: the row
 * still rendered). The merge-simulation test below pins exactly that.
 *
 * @covers \MidgardWhmcs\ClientAreaDisplay
 */
final class ClientAreaDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        $capsule = new Capsule();
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        Capsule::schema()->create('tblproducts', static function ($table): void {
            $table->increments('id');
            $table->string('servertype')->default('other');
        });

        Capsule::schema()->create('tblhosting', static function ($table): void {
            $table->increments('id');
            $table->integer('userid')->default(0);
            $table->integer('packageid')->default(0);
            $table->string('domainstatus')->default('Active');
            $table->string('dedicatedip')->default('');
            $table->text('assignedips')->nullable();
        });
    }

    public function test_midgard_service_returns_empty_overrides_for_both_ip_vars(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        Capsule::table('tblhosting')->insert([
            'id' => 42,
            'userid' => 3,
            'packageid' => 7,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
        ]);

        $overrides = ClientAreaDisplay::filterProductDetailsVars([
            'serviceid' => 42,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
        ]);

        $this->assertSame(['dedicatedip' => '', 'assignedips' => ''], $overrides);
    }

    /**
     * THE regression test for the live failure: WHMCS merges the hook
     * result into the template vars, so after the merge both IP vars must
     * be '' — six-style templates guard the rows with {if $var}, hiding them.
     */
    public function test_whmcs_merge_semantics_result_in_blank_rows(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        Capsule::table('tblhosting')->insert([
            'id' => 42,
            'userid' => 3,
            'packageid' => 7,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
        ]);

        $templateVars = [
            'serviceid' => 42,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
            'domain' => 'example.com',
        ];

        // Exactly what WHMCS does with a ClientAreaPage* hook response:
        $merged = array_merge($templateVars, ClientAreaDisplay::filterProductDetailsVars($templateVars));

        $this->assertSame('', $merged['dedicatedip'], 'row guard {if $dedicatedip} must go falsy');
        $this->assertSame('', $merged['assignedips'], 'row guard {if $assignedips} must go falsy');
        $this->assertSame('example.com', $merged['domain'], 'unrelated vars must survive');
    }

    public function test_service_id_is_also_resolved_from_id_key(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        Capsule::table('tblhosting')->insert([
            'id' => 55,
            'userid' => 3,
            'packageid' => 7,
            'dedicatedip' => '103.1.2.6',
            'assignedips' => '',
        ]);

        $overrides = ClientAreaDisplay::filterProductDetailsVars(['id' => 55]);

        $this->assertSame(['dedicatedip' => '', 'assignedips' => ''], $overrides);
    }

    public function test_non_midgard_service_returns_no_overrides(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 9, 'servertype' => 'cpanel']);
        Capsule::table('tblhosting')->insert([
            'id' => 43,
            'userid' => 3,
            'packageid' => 9,
            'dedicatedip' => '103.1.2.4',
            'assignedips' => '',
        ]);

        $this->assertSame([], ClientAreaDisplay::filterProductDetailsVars(['serviceid' => 43]));
    }

    public function test_unknown_service_id_returns_no_overrides(): void
    {
        $this->assertSame([], ClientAreaDisplay::filterProductDetailsVars(['serviceid' => 999999]));
    }

    public function test_missing_service_id_returns_no_overrides(): void
    {
        $this->assertSame([], ClientAreaDisplay::filterProductDetailsVars(['dedicatedip' => '1.2.3.4']));
    }

    public function test_db_columns_are_never_written(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        Capsule::table('tblhosting')->insert([
            'id' => 44,
            'userid' => 5,
            'packageid' => 7,
            'dedicatedip' => '103.9.9.9',
            'assignedips' => '2a02:9::1/64',
        ]);

        ClientAreaDisplay::filterProductDetailsVars([
            'serviceid' => 44,
            'dedicatedip' => '103.9.9.9',
            'assignedips' => '2a02:9::9/64',
        ]);

        $row = Capsule::table('tblhosting')->where('id', 44)->first();
        $this->assertSame('103.9.9.9', $row->dedicatedip, 'native column must stay live for admin search');
        $this->assertSame('2a02:9::1/64', $row->assignedips, 'native column must stay live for admin search');
    }
}
