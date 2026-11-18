<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_backups', function (Blueprint $table) {
            // true = perubahan ini BENAR-BENAR sudah diterapkan ke ONT (hanya ini yang di-rollback)
            $table->boolean('applied')->default(false)->after('is_secret');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_backups', fn (Blueprint $table) => $table->dropColumn('applied'));
    }
};
