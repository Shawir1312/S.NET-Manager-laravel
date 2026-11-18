<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema standar FreeRADIUS (rlm_sql_mysql). Nama tabel & kolom TIDAK boleh diubah:
 * FreeRADIUS membaca/menulis tabel ini langsung.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['radcheck' => ':=', 'radreply' => '='] as $name => $op) {
            Schema::create($name, function (Blueprint $t) use ($op) {
                $t->increments('id');
                $t->string('username', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default($op);
                $t->string('value', 253)->default('');
            });
        }
        foreach (['radgroupcheck' => ':=', 'radgroupreply' => '='] as $name => $op) {
            Schema::create($name, function (Blueprint $t) use ($op) {
                $t->increments('id');
                $t->string('groupname', 64)->default('')->index();
                $t->string('attribute', 64)->default('');
                $t->char('op', 2)->default($op);
                $t->string('value', 253)->default('');
            });
        }
        Schema::create('radusergroup', function (Blueprint $t) {
            $t->string('username', 64)->default('')->index();
            $t->string('groupname', 64)->default('');
            $t->integer('priority')->default(1);
        });
        Schema::create('radacct', function (Blueprint $t) {
            $t->bigIncrements('radacctid');
            $t->string('acctsessionid', 64)->default('')->index();
            $t->string('acctuniqueid', 32)->default('')->unique();
            $t->string('username', 64)->default('')->index();
            $t->string('realm', 64)->nullable()->default('');
            $t->string('nasipaddress', 15)->default('')->index();
            $t->string('nasportid', 15)->nullable();
            $t->string('nasporttype', 32)->nullable();
            $t->dateTime('acctstarttime')->nullable()->index();
            $t->dateTime('acctupdatetime')->nullable()->index();
            $t->dateTime('acctstoptime')->nullable()->index();
            $t->integer('acctinterval')->nullable();
            $t->unsignedInteger('acctsessiontime')->nullable();
            $t->string('acctauthentic', 32)->nullable();
            $t->string('connectinfo_start', 50)->nullable();
            $t->string('connectinfo_stop', 50)->nullable();
            $t->bigInteger('acctinputoctets')->nullable();
            $t->bigInteger('acctoutputoctets')->nullable();
            $t->string('calledstationid', 50)->default('');
            $t->string('callingstationid', 50)->default('');
            $t->string('acctterminatecause', 32)->default('');
            $t->string('servicetype', 32)->nullable();
            $t->string('framedprotocol', 32)->nullable();
            $t->string('framedipaddress', 15)->default('')->index();
            $t->string('framedipv6address', 45)->default('');
            $t->string('framedipv6prefix', 45)->default('');
            $t->string('framedinterfaceid', 44)->default('');
            $t->string('delegatedipv6prefix', 45)->default('');
            $t->string('class', 64)->nullable();
        });
        Schema::create('radpostauth', function (Blueprint $t) {
            $t->increments('id');
            $t->string('username', 64)->default('')->index();
            $t->string('pass', 64)->default('');
            $t->string('reply', 32)->default('');
            $t->timestamp('authdate')->useCurrent();
        });
        Schema::create('nas', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nasname', 128)->index();
            $t->string('shortname', 32)->nullable();
            $t->string('type', 30)->nullable()->default('other');
            $t->integer('ports')->nullable();
            $t->string('secret', 60)->default('secret');
            $t->string('server', 64)->nullable();
            $t->string('community', 50)->nullable();
            $t->string('description', 200)->nullable()->default('RADIUS Client');
        });
    }

    public function down(): void
    {
        foreach (['nas', 'radpostauth', 'radacct', 'radusergroup', 'radgroupreply', 'radgroupcheck', 'radreply', 'radcheck'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
