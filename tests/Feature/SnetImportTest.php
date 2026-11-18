<?php

namespace Tests\Feature;

use App\Models\GenieAcsSetting;
use App\Models\PppoeCustomer;
use App\Models\Router;
use App\Models\User;
use App\Services\Settings\SnetSettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SnetImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $s = Schema::connection('legacy');
        $s->create('admins', function (Blueprint $t) {
            $t->id();
            $t->string('username');
            $t->string('password');
            $t->string('full_name')->nullable();
            $t->string('role');
            $t->text('router_access')->nullable();
            $t->boolean('is_active');
            $t->timestamp('created_at')->nullable();
        });
        $s->create('genie_config', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('url');
            $t->string('username');
            $t->string('password');
            $t->boolean('is_active');
        });
        $s->create('routers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('ip_address');
            $t->string('api_user');
            $t->string('api_password');
            $t->integer('api_port');
            $t->string('radius_secret');
            $t->string('status');
            $t->integer('genie_server_id')->nullable();
            $t->integer('default_vlan');
            $t->timestamp('created_at')->nullable();
        });
        $s->create('pppoe_customers', function (Blueprint $t) {
            $t->id();
            $t->integer('router_id');
            $t->string('pppoe_username');
            $t->string('portal_username');
            $t->string('portal_password');
            $t->string('portal_password_plain');
            $t->string('full_name');
            $t->string('phone');
            $t->string('profile');
            $t->integer('monthly_price');
            $t->integer('due_day');
            $t->string('status');
            $t->string('ont_sn');
            $t->string('ont_wifi_pass');
            $t->integer('created_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });
        $s->create('pppoe_settings', function (Blueprint $t) {
            $t->id();
            $t->string('setting_key');
            $t->text('setting_value');
        });
        $s->create('radcheck', function (Blueprint $t) {
            $t->id();
            $t->string('username');
            $t->string('attribute');
            $t->string('op');
            $t->string('value');
        });

        $db = DB::connection('legacy');
        $db->table('admins')->insert([
            ['id' => 7, 'username' => 'bos', 'password' => password_hash('lamaBos1', PASSWORD_DEFAULT), 'full_name' => 'Pak Bos', 'role' => 'superadmin', 'router_access' => null, 'is_active' => 1, 'created_at' => now()],
            ['id' => 8, 'username' => 'kasir', 'password' => password_hash('kasir123', PASSWORD_DEFAULT), 'full_name' => 'Kasir', 'role' => 'operator', 'router_access' => '[1]', 'is_active' => 1, 'created_at' => now()],
        ]);
        $db->table('genie_config')->insert(['id' => 3, 'name' => 'ACS', 'url' => 'http://acs.lama:7557/', 'username' => 'nbi', 'password' => 'nbipass', 'is_active' => 1]);
        $db->table('routers')->insert(['id' => 5, 'name' => 'MT-Pusat', 'ip_address' => '10.0.0.1', 'api_user' => 'admin', 'api_password' => 'apipass', 'api_port' => 8728, 'radius_secret' => 'rad', 'status' => 'active', 'genie_server_id' => 3, 'default_vlan' => 100, 'created_at' => now()]);
        $db->table('pppoe_customers')->insert([
            ['id' => 11, 'router_id' => 5, 'pppoe_username' => 'budi@snet', 'portal_username' => 'budi', 'portal_password' => '', 'portal_password_plain' => '445566', 'full_name' => 'Budi', 'phone' => '0812', 'profile' => '10M', 'monthly_price' => 150000, 'due_day' => 5, 'status' => 'active', 'ont_sn' => 'fhtt0001', 'ont_wifi_pass' => 'wifibudi1', 'created_by' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'router_id' => 5, 'pppoe_username' => 'ani@snet', 'portal_username' => '', 'portal_password' => password_hash('aniani', PASSWORD_DEFAULT), 'portal_password_plain' => '', 'full_name' => 'Ani', 'phone' => '', 'profile' => '10M', 'monthly_price' => 0, 'due_day' => 1, 'status' => 'isolated', 'ont_sn' => '0', 'ont_wifi_pass' => '', 'created_by' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $db->table('pppoe_settings')->insert([['setting_key' => 'company_name', 'setting_value' => 'S.NET'], ['setting_key' => 'midtrans_server_key', 'setting_value' => 'SB-xyz']]);
        $db->table('radcheck')->insert(['username' => 'budi@snet', 'attribute' => 'Cleartext-Password', 'op' => ':=', 'value' => '445566']);
    }

    public function test_import_maps_data_and_is_idempotent(): void
    {
        $this->artisan('snet:import')->assertSuccessful();
        $this->artisan('snet:import')->assertSuccessful();

        $this->assertSame(2, User::count());
        $kasir = User::where('username', 'kasir')->firstOrFail();
        $this->assertSame('teknisi', $kasir->role->value);
        $this->post('/login', ['email' => 'bos', 'password' => 'lamaBos1'])->assertRedirect(route('dashboard'));

        $server = GenieAcsSetting::firstOrFail();
        $this->assertSame('http://acs.lama:7557', $server->nbi_url);
        $this->assertSame('nbipass', $server->password);
        $router = Router::findOrFail(5);
        $this->assertSame($server->id, $router->genie_acs_setting_id);
        $this->assertSame('apipass', $router->api_password);

        $this->assertSame(2, PppoeCustomer::count());
        $budi = PppoeCustomer::findOrFail(11);
        $this->assertTrue(password_verify('445566', $budi->portal_password));
        $this->assertSame('FHTT0001', $budi->ont_sn);
        $this->assertSame('wifibudi1', $budi->ont_wifi_pass);
        $this->assertSame($kasir->id, $budi->created_by);
        $this->assertTrue(password_verify('aniani', PppoeCustomer::findOrFail(12)->portal_password));
        $this->assertSame('', PppoeCustomer::findOrFail(12)->ont_sn);

        $this->assertSame('SB-xyz', app(SnetSettings::class)->secret('pppoe', 'midtrans_server_key'));
        $this->assertSame(1, DB::table('radcheck')->count());
    }
}
