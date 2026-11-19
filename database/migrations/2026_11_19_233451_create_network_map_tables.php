<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Peta jaringan: lokasi server (router/cabang) → ODP → pelanggan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $t) {
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });

        Schema::create('odps', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('router_id')->index();
            $t->string('name', 100);
            $t->decimal('latitude', 10, 7);
            $t->decimal('longitude', 10, 7);
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->string('notes', 255)->nullable();
            $t->timestamps();
        });

        Schema::table('pppoe_customers', function (Blueprint $t) {
            $t->unsignedBigInteger('odp_id')->nullable()->index();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pppoe_customers', fn (Blueprint $t) => $t->dropColumn(['odp_id', 'latitude', 'longitude']));
        Schema::dropIfExists('odps');
        Schema::table('routers', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
    }
};
