<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S.NET memakai beberapa server GenieACS; ONT dicatat milik server mana. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genieacs_settings', function (Blueprint $table) {
            $table->string('name', 100)->default('GenieACS')->after('id');
        });
        Schema::table('ont_devices', function (Blueprint $table) {
            $table->foreignId('genie_acs_setting_id')->nullable()->after('id')->constrained('genieacs_settings')->nullOnDelete();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 64)->nullable()->unique()->after('name');
            $table->json('router_access')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['username', 'router_access']));
        Schema::table('ont_devices', fn (Blueprint $t) => $t->dropConstrainedForeignId('genie_acs_setting_id'));
        Schema::table('genieacs_settings', fn (Blueprint $t) => $t->dropColumn('name'));
    }
};
