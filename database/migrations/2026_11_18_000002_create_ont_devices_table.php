<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cache lokal perangkat dari GenieACS. Sumber kebenaran tetap GenieACS.
        Schema::create('ont_devices', function (Blueprint $table) {
            $table->id();
            $table->string('genieacs_id', 191)->unique();    // _id GenieACS: OUI-ProductClass-SerialNumber
            $table->string('serial_number', 64)->unique();   // _deviceId._SerialNumber
            $table->string('manufacturer', 100)->nullable();
            $table->string('vendor', 20)->nullable()->index();
            $table->string('oui', 10)->nullable();
            $table->string('product_class', 100)->nullable();
            $table->foreignId('ont_model_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hardware_version', 100)->nullable();
            $table->string('software_version', 100)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_inform_at')->nullable()->index();
            $table->timestamp('last_online_check_at')->nullable();
            $table->boolean('last_online_result')->nullable(); // hasil Connection Request terakhir
            $table->json('parameters_snapshot')->nullable();  // ringkasan parameter terakhir yang dibaca
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ont_devices');
    }
};
