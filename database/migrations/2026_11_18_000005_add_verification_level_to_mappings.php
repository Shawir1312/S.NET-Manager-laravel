<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ont_parameter_mappings', function (Blueprint $table) {
            // path   = path ditemukan di ONT asli (otomatis saat cari/refresh)
            // write  = ditulis lalu dibaca ulang cocok (otomatis setelah job produksi)
            // manual = ditandai admin di Device Models
            $table->string('verification_level', 10)->nullable()->after('verified');
            $table->string('verified_device', 64)->nullable()->after('verification_level');
        });
    }

    public function down(): void
    {
        Schema::table('ont_parameter_mappings', function (Blueprint $table) {
            $table->dropColumn(['verification_level', 'verified_device']);
        });
    }
};
