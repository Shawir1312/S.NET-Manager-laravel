<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('service_type', 20);   // App\Enums\ServiceType
            $table->text('description')->nullable();
            $table->json('payload');              // TANPA password PPPoE / WiFi
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('configuration_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ont_device_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('configuration_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_type', 20);
            $table->text('request_payload');      // JSON terenkripsi (berisi secret)
            $table->json('plan')->nullable();     // rencana langkah, secret sudah diredaksi
            $table->string('status', 20)->default('pending')->index();
            $table->boolean('is_simulation')->default(false)->index();
            $table->string('idempotency_key', 64)->unique();
            $table->json('genieacs_task_ids')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['ont_device_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('wan_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ont_device_id')->constrained()->cascadeOnDelete();
            $table->string('wan_name', 100)->nullable();
            $table->string('slot', 10);                // ip | ppp
            $table->string('instance_path', 255)->nullable(); // path instance WAN di ONT
            $table->boolean('created_new')->default(false);   // dibuat lewat AddObject?
            $table->string('connection_type', 30);     // IP_Routed / IP_Bridged / PPPoE_Bridged
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->unsignedTinyInteger('vlan_priority')->nullable();
            $table->string('service_list', 50)->nullable();
            $table->boolean('nat_enabled')->nullable();
            $table->string('pppoe_username', 128)->nullable();
            $table->text('pppoe_password')->nullable(); // terenkripsi
            $table->timestamps();

            $table->index(['ont_device_id', 'vlan_id']);
        });

        Schema::create('ssid_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('ssid_index');
            $table->string('ssid_name', 64)->nullable();
            $table->text('password')->nullable();      // terenkripsi
            $table->string('security_mode', 20)->nullable(); // None / 11i / WPAand11i
            $table->boolean('enabled')->nullable();
            $table->boolean('hidden')->nullable();
            $table->unsignedSmallInteger('channel')->nullable();
            $table->timestamps();

            $table->unique(['configuration_job_id', 'ssid_index']);
        });

        Schema::create('interface_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wan_configuration_id')->constrained()->cascadeOnDelete();
            $table->string('interface_type', 10);   // lan | wlan | wan
            $table->unsignedTinyInteger('interface_index');
            $table->string('interface_path', 255);
            $table->timestamps();

            $table->unique(['wan_configuration_id', 'interface_type', 'interface_index'], 'binding_unique');
        });

        Schema::create('configuration_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_job_id')->constrained()->cascadeOnDelete();
            $table->string('parameter_path', 255);
            $table->text('old_value')->nullable();  // terenkripsi bila is_secret
            $table->string('old_type', 30)->nullable();
            $table->text('new_value')->nullable();  // terenkripsi bila is_secret
            $table->boolean('existed_before')->default(true);
            $table->boolean('is_secret')->default(false);
            $table->string('restore_status', 20)->nullable(); // restored | failed | skipped
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['configuration_job_id', 'parameter_path']);
        });

        Schema::create('configuration_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('configuration_job_id')->constrained()->cascadeOnDelete();
            $table->string('event', 50);     // queued, backup, add_object, set_values, task_done, verify, rollback, ...
            $table->string('status', 20);    // info | success | warning | error
            $table->text('message');
            $table->json('meta')->nullable(); // sudah diredaksi
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_histories');
        Schema::dropIfExists('configuration_backups');
        Schema::dropIfExists('interface_bindings');
        Schema::dropIfExists('ssid_configurations');
        Schema::dropIfExists('wan_configurations');
        Schema::dropIfExists('configuration_jobs');
        Schema::dropIfExists('configuration_templates');
    }
};
