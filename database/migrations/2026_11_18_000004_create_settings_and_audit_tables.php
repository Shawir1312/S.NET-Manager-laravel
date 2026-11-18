<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genieacs_settings', function (Blueprint $table) {
            $table->id();
            $table->string('nbi_url', 255);
            $table->string('cwmp_url', 255)->nullable();
            $table->text('username')->nullable();   // terenkripsi
            $table->text('password')->nullable();   // terenkripsi
            $table->unsignedSmallInteger('connect_timeout')->default(5);   // detik
            $table->unsignedSmallInteger('request_timeout')->default(30);  // detik
            $table->unsignedSmallInteger('connection_request_timeout')->default(10); // detik, ditunggu NBI
            $table->boolean('verify_tls')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('last_test_status', 20)->nullable(); // ok | failed
            $table->string('last_test_message', 255)->nullable();
            $table->timestamp('last_test_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->json('changes')->nullable(); // selalu diredaksi oleh AuditLogger
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('genieacs_settings');
    }
};
