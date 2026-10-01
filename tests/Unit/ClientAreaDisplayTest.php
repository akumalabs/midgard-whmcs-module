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

    public function test_midgard_service_has_native_ip_display_vars_removed(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 7, 'servertype' => 'midgard']);
        Capsule::table('tblhosting')->insert([
            'id' => 42,
            'userid' => 3,
            'packageid' => 7,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
        ]);

        $filtered = ClientAreaDisplay::filterProductDetailsVars([
            'serviceid' => 42,
            'dedicatedip' => '103.1.2.3',
            'assignedips' => '2a02:1::5/64',
            'domain' => 'example.com',
            'username' => 'user42',
        ]);

        $this->assertArrayNotHasKey('dedicatedip', $filtered);
        $this->assertArrayNotHasKey('assignedips', $filtered);
        $this->assertSame('example.com', $filtered['domain'], 'unrelated vars must survive');
        $this->assertSame('user42', $filtered['username'], 'unrelated vars must survive');
    }

    public function test_non_midgard_service_is_passed_through_untouched(): void
    {
        Capsule::table('tblproducts')->insert(['id' => 9, 'servertype' => 'cpanel']);
        Capsule::table('tblhosting')->insert([
            'id' => 43,
            'userid' => 3,
            'packageid' => 9,
            'dedicatedip' => '103.1.2.4',
            'assignedips' => '',
        ]);

        $vars = [
            'serviceid' => 43,
            'dedicatedip' => '103.1.2.4',
            'assignedips' => '',
        ];

        $this->assertSame($vars, ClientAreaDisplay::filterProductDetailsVars($vars));
    }

    public function test_unknown_service_id_is_passed_through_untouched(): void
    {
        $vars = ['serviceid' => 999999, 'dedicatedip' => '1.2.3.4'];

        $this->assertSame($vars, ClientAreaDisplay::filterProductDetailsVars($vars));
    }

    public function test_missing_service_id_is_passed_through_untouched(): void
    {
        $vars = ['dedicatedip' => '1.2.3.4', 'assignedips' => 'a::1'];

        $this->assertSame($vars, ClientAreaDisplay::filterProductDetailsVars($vars));
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
            'assignedips' => '2a02:9::1/64',
        ]);

        $row = Capsule::table('tblhosting')->where('id', 44)->first();
        $this->assertSame('103.9.9.9', $row->dedicatedip, 'native column must stay live for admin search');
        $this->assertSame('2a02:9::1/64', $row->assignedips, 'native column must stay live for admin search');
    }
}
