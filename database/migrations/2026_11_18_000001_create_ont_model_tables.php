<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ont_models', function (Blueprint $table) {
            $table->id();
            $table->string('vendor', 20)->index();            // App\Enums\Vendor
            $table->string('model_name', 100);                 // nama tampilan, mis. "F670L"
            $table->string('product_class', 100);              // DeviceID.ProductClass dari GenieACS
            $table->string('firmware_pattern', 191)->default('*'); // "*" = semua firmware, atau regex
            $table->string('status', 20)->default('testing');  // App\Enums\ModelSupportStatus
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['vendor', 'product_class', 'firmware_pattern'], 'ont_models_identity_unique');
        });

        Schema::create('ont_capabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ont_model_id')->unique()->constrained()->cascadeOnDelete();
            // null = belum diketahui (belum diverifikasi dari ONT asli)
            $table->unsignedTinyInteger('max_wan_ip')->nullable();
            $table->unsignedTinyInteger('max_wan_ppp')->nullable();
            $table->unsignedTinyInteger('lan_ports')->nullable();
            $table->unsignedTinyInteger('ssid_count')->nullable();
            $table->boolean('has_5g')->nullable();
            $table->boolean('supports_bridge')->default(false);
            $table->boolean('supports_pppoe')->default(false);
            $table->string('binding_method', 20)->default('unknown'); // App\Enums\BindingMethod
            $table->unsignedSmallInteger('vlan_min')->default(1);
            $table->unsignedSmallInteger('vlan_max')->default(4094);
            $table->json('extra')->nullable();
            $table->timestamps();
        });

        Schema::create('ont_parameter_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ont_model_id')->constrained()->cascadeOnDelete();
            $table->string('key', 100);            // kunci logis, mis. "wan.vlan_id"
            $table->string('path_template', 255);  // mis. "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}.WANIPConnection.{i}.VLANID"
            $table->string('value_type', 30)->default('xsd:string');
            $table->json('value_map')->nullable(); // mis. {"bridge":"IP_Bridged"}
            $table->boolean('verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->string('source', 191)->nullable(); // dari mana path ini diketahui
            $table->timestamps();

            $table->unique(['ont_model_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ont_parameter_mappings');
        Schema::dropIfExists('ont_capabilities');
        Schema::dropIfExists('ont_models');
    }
};
