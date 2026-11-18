<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ServiceType;
use App\Enums\Vendor;
use App\Models\ConfigurationJob;
use App\Models\OntDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_for_every_role(): void
    {
        foreach (['admin', 'teknisi', 'viewer'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create())->get('/dashboard')
                ->assertOk()->assertSee('Total ONT')->assertSee('SIMULASI');
        }
    }

    public function test_stats_count_devices_and_exclude_simulation_jobs(): void
    {
        $fh = OntDevice::create(['genieacs_id' => '000AC2-HG6145D2-FHTT1', 'serial_number' => 'FHTT1', 'vendor' => Vendor::FiberHome, 'last_inform_at' => now()]);
        OntDevice::create(['genieacs_id' => 'ZTE-F670L-ZTEG1', 'serial_number' => 'ZTEG1', 'vendor' => Vendor::ZTE, 'last_inform_at' => now()->subDay()]);
        OntDevice::create(['genieacs_id' => 'X-Y-Z1', 'serial_number' => 'Z1', 'vendor' => null]);

        $make = fn (JobStatus $status, bool $sim) => ConfigurationJob::create([
            'ont_device_id' => $fh->id, 'service_type' => ServiceType::Pppoe, 'request_payload' => ['pppoe_password' => 'x'],
            'status' => $status, 'is_simulation' => $sim, 'idempotency_key' => Str::random(40), 'finished_at' => now(),
        ]);
        $make(JobStatus::Success, false);
        $make(JobStatus::Failed, false);
        $make(JobStatus::Success, true);

        $json = $this->actingAs(User::factory()->viewer()->create())->getJson('/api/dashboard/stats')->assertOk()->json();

        $this->assertSame(3, $json['devices']['total']);
        $this->assertSame(1, $json['devices']['online']);
        $this->assertSame(2, $json['devices']['offline']);
        $this->assertSame(1, $json['vendors']['fiberhome']['total']);
        $this->assertSame(1, $json['vendors']['zte']['total']);
        $this->assertSame(1, $json['vendors']['other']['total']);
        $this->assertSame(1, $json['configurations']['success']);
        $this->assertSame(1, $json['configurations']['failed']);
        $this->assertSame(1, $json['configurations']['simulation_total']);
        $this->assertCount(14, $json['activity']['labels']);
        $this->assertSame(1, array_sum($json['activity']['success']));
    }

    public function test_job_payload_is_encrypted_and_hidden(): void
    {
        $device = OntDevice::create(['genieacs_id' => 'A-B-C', 'serial_number' => 'C']);
        $job = ConfigurationJob::create([
            'ont_device_id' => $device->id, 'service_type' => ServiceType::Pppoe,
            'request_payload' => ['pppoe_username' => 'pelanggan1', 'pppoe_password' => 'RahasiaPPP'],
            'idempotency_key' => Str::random(40),
        ]);

        $raw = \DB::table('configuration_jobs')->where('id', $job->id)->value('request_payload');
        $this->assertStringNotContainsString('RahasiaPPP', $raw);
        $this->assertSame('RahasiaPPP', $job->fresh()->request_payload['pppoe_password']);
        $this->assertArrayNotHasKey('request_payload', $job->toArray());
    }
}
