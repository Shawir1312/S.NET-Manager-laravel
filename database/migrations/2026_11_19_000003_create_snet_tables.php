<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel domain S.NET. Nama & kolom sengaja mengikuti skema lama agar `snet:import`
 * cukup menyalin data. Perubahan: admins -> users, genie_config -> genieacs_settings,
 * audit_log -> audit_logs, password portal hanya disimpan sebagai hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('ip_address', 45)->unique();
            $t->unsignedInteger('nas_id')->nullable();
            $t->string('nas_ip', 128)->default('0.0.0.0/0');
            $t->string('api_user', 64)->default('admin');
            $t->text('api_password')->nullable();
            $t->unsignedSmallInteger('api_port')->default(8728);
            $t->string('radius_secret', 128);
            $t->text('location')->nullable();
            $t->string('status', 10)->default('active');
            $t->foreignId('genie_acs_setting_id')->nullable()->constrained('genieacs_settings')->nullOnDelete();
            $t->unsignedSmallInteger('default_vlan')->default(100);
            $t->foreignId('pppoe_template_id')->nullable()->constrained('configuration_templates')->nullOnDelete();
            $t->foreignId('hotspot_template_id')->nullable()->constrained('configuration_templates')->nullOnDelete();
            $t->dateTime('last_seen')->nullable();
            $t->timestamps();
        });

        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('display_name', 100)->nullable();
            $t->integer('validity_value')->default(30);
            $t->string('validity_unit', 32)->default('days');
            $t->integer('duration_value')->default(30);
            $t->string('duration_unit', 32)->default('days');
            $t->bigInteger('quota_mb')->default(0);
            $t->string('rate_up', 20)->default('0');
            $t->string('rate_down', 20)->default('0');
            $t->decimal('price', 10, 2)->default(0);
            $t->boolean('include_in_sales')->default(true);
            $t->decimal('reseller_percent', 5, 2)->default(0);
            $t->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('vouchers', function (Blueprint $t) {
            $t->id();
            $t->string('username', 64)->unique();
            $t->string('password', 128);
            $t->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $t->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $t->string('batch_id', 64)->nullable()->index();
            $t->string('status', 20)->default('unused')->index();
            $t->dateTime('used_at')->nullable();
            $t->dateTime('expired_at')->nullable();
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('synced_to_master')->default(false)->index();
            $t->timestamps();
        });

        Schema::create('sales_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('voucher_id')->nullable();
            $t->string('voucher_username', 64)->nullable();
            $t->unsignedBigInteger('profile_id')->nullable();
            $t->string('profile_name', 100)->nullable();
            $t->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('sold_by')->nullable();
            $t->decimal('price', 10, 2)->default(0);
            $t->timestamp('sold_at')->useCurrent()->index();
            $t->boolean('synced_to_master')->default(false)->index();
        });

        Schema::create('penagihan', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->constrained()->cascadeOnDelete();
            $t->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $t->decimal('total_pendapatan', 15, 2)->default(0);
            $t->decimal('bagian_reseller', 15, 2)->default(0);
            $t->decimal('pendapatan_bersih', 15, 2)->default(0);
            $t->integer('estimasi_voucher')->default(0);
            $t->integer('voucher_aktual')->default(0);
            $t->string('status_kecocokan', 10)->default('sesuai');
            $t->text('catatan')->nullable();
            $t->unsignedBigInteger('ditagih_oleh');
            $t->date('tanggal')->index();
            $t->timestamps();
        });

        Schema::create('pppoe_customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->constrained()->cascadeOnDelete();
            $t->string('pppoe_username', 100);
            $t->string('portal_username', 100)->nullable()->unique();
            $t->string('portal_password')->nullable();
            $t->string('full_name', 150)->default('');
            $t->string('phone', 25)->default('');
            $t->text('address')->nullable();
            $t->string('profile', 100)->default('');
            $t->unsignedInteger('monthly_price')->default(0);
            $t->boolean('is_free')->default(false);
            $t->unsignedTinyInteger('due_day')->default(1);
            $t->string('status', 12)->default('active');
            $t->string('ont_sn', 100)->default('')->index();
            $t->unsignedSmallInteger('ont_vlan')->default(100);
            $t->string('ont_wifi_ssid', 100)->default('');
            $t->text('ont_wifi_pass')->nullable();
            $t->dateTime('isolated_at')->nullable();
            $t->string('isolated_reason')->default('');
            $t->date('last_paid_at')->nullable();
            $t->unsignedInteger('last_paid_amount')->default(0);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['router_id', 'pppoe_username']);
            $t->index(['router_id', 'status']);
        });

        Schema::create('pppoe_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('pppoe_customers')->cascadeOnDelete();
            $t->unsignedInteger('amount');
            $t->string('payment_method', 50)->default('cash');
            $t->string('payment_gateway', 50)->nullable();
            $t->string('midtrans_order_id', 100)->nullable()->index();
            $t->string('midtrans_tx_id', 100)->nullable();
            $t->string('midtrans_status', 50)->nullable();
            $t->text('checkout_url')->nullable();
            $t->string('pay_code', 100)->nullable();
            $t->text('qr_url')->nullable();
            $t->unsignedTinyInteger('period_month');
            $t->unsignedSmallInteger('period_year');
            $t->dateTime('paid_at')->nullable();
            $t->string('notes')->default('');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['customer_id', 'period_year', 'period_month']);
            $t->index(['midtrans_status', 'payment_method']);
        });

        foreach (['pppoe_settings' => 'setting_key', 'wg_settings' => 'key', 'redundant_settings' => 'key'] as $name => $keyCol) {
            Schema::create($name, function (Blueprint $t) use ($keyCol, $name) {
                if ($name === 'pppoe_settings') {
                    $t->id();
                    $t->string($keyCol, 100)->unique();
                    $t->text('setting_value')->nullable();
                } else {
                    $t->string($keyCol, 100)->primary();
                    $t->text('value');
                }
                $t->timestamp('updated_at')->nullable();
            });
        }

        Schema::create('pppoe_bandwidth_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->constrained()->cascadeOnDelete();
            $t->string('username', 128);
            $t->char('month_year', 7);
            $t->unsignedBigInteger('committed_dl')->default(0);
            $t->unsignedBigInteger('committed_ul')->default(0);
            $t->unsignedBigInteger('last_if_tx')->default(0);
            $t->unsignedBigInteger('last_if_rx')->default(0);
            $t->timestamps();
            $t->unique(['router_id', 'username', 'month_year']);
            $t->index(['username', 'month_year']);
        });

        Schema::create('wa_config', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 50)->default('waweb');
            $t->string('api_url')->default('http://127.0.0.1:3000/api/send');
            $t->text('api_token')->nullable();
            $t->string('device_id', 100)->default('');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('wa_templates', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name', 100);
            $t->text('message');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('wa_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('phone', 30);
            $t->string('recipient_name', 150)->default('');
            $t->string('message_type', 50)->default('general');
            $t->text('message_text');
            $t->string('status', 10)->default('pending');
            $t->text('response_payload')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('wa_inbox', function (Blueprint $t) {
            $t->id();
            $t->string('message_id', 100)->default('');
            $t->string('sender_phone', 30)->index();
            $t->string('sender_name', 150)->default('');
            $t->text('message_text')->nullable();
            $t->string('message_type', 30)->default('text');
            $t->string('direction', 3)->default('in');
            $t->boolean('is_group')->default(false);
            $t->string('group_jid', 100)->default('');
            $t->string('group_name', 150)->default('');
            $t->boolean('is_read')->default(false)->index();
            $t->dateTime('replied_at')->nullable();
            $t->text('reply_text')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('wg_routers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('location', 150)->nullable();
            $t->string('public_key')->unique();
            $t->text('private_key');
            $t->string('tunnel_ip', 20)->unique();
            $t->unsignedInteger('listen_port')->default(13231);
            $t->text('lan_subnets')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });

        Schema::create('wg_port_forwards', function (Blueprint $t) {
            $t->id();
            $t->foreignId('router_id')->constrained('wg_routers')->cascadeOnDelete();
            $t->unsignedInteger('public_port');
            $t->unsignedInteger('target_port');
            $t->string('target_ip', 20)->nullable();
            $t->string('protocol', 10)->default('tcp');
            $t->timestamps();
            $t->unique(['public_port', 'protocol']);
        });

        Schema::create('wg_logs', function (Blueprint $t) {
            $t->id();
            $t->string('event', 100);
            $t->unsignedBigInteger('router_id')->nullable();
            $t->string('router_name', 100)->nullable();
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('ont_remotes', function (Blueprint $t) {
            $t->id();
            $t->string('ont_sn', 100)->index();
            $t->string('ont_name', 150)->nullable();
            $t->string('ont_ip', 50);
            $t->unsignedInteger('target_port')->default(80);
            $t->unsignedInteger('public_port');
            $t->dateTime('expires_at');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->string('type', 15)->default('info');
            $t->string('title', 200)->default('');
            $t->text('body');
            $t->boolean('is_active')->default(true);
            $t->boolean('show_on_login')->default(true);
            $t->boolean('maintenance_mode')->default(false);
            $t->dateTime('start_at')->nullable();
            $t->dateTime('end_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('redundant_nodes', function (Blueprint $t) {
            $t->id();
            $t->string('node_name', 100);
            $t->string('node_code', 50)->unique();
            $t->unsignedBigInteger('router_id')->default(0);
            $t->string('node_ip', 50)->nullable();
            $t->string('api_key', 64);
            $t->string('status', 10)->default('active');
            $t->dateTime('last_sync_at')->nullable();
            $t->string('last_sync_status')->nullable();
            $t->unsignedInteger('total_vouchers_synced')->default(0);
            $t->timestamps();
        });

        Schema::create('redundant_logs', function (Blueprint $t) {
            $t->id();
            $t->string('node_code', 50);
            $t->string('direction', 5);
            $t->string('status', 10);
            $t->string('summary');
            $t->text('details')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach ([
            'redundant_logs', 'redundant_nodes', 'announcements', 'ont_remotes', 'wg_logs', 'wg_port_forwards',
            'wg_routers', 'wa_inbox', 'wa_logs', 'wa_templates', 'wa_config', 'pppoe_bandwidth_snapshots',
            'redundant_settings', 'wg_settings', 'pppoe_settings', 'pppoe_payments', 'pppoe_customers',
            'penagihan', 'sales_log', 'vouchers', 'profiles', 'routers',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
