<?php

namespace App\Services\Provisioning;

use App\Enums\JobStatus;
use App\Enums\ServiceType;
use App\Jobs\RunProvisioningJob;
use App\Models\ConfigurationBackup;
use App\Models\ConfigurationJob;
use App\Models\OntDevice;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\Redactor;
use App\Services\GenieACS\Exceptions\GenieACSException;
use App\Services\GenieACS\GenieACSService;
use App\Services\ONT\DeviceContext;
use App\Services\ONT\Mappers\WANMapper;
use App\Services\ONT\MappingVerifier;
use App\Services\ONT\ONTDetector;
use App\Services\ONT\ONTManager;
use App\Services\ONT\Validators\ONTValidator;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;

/**
 * Mesin provisioning: validasi -> backup -> AddObject -> SetParameterValues ->
 * pantau task -> baca ulang -> verifikasi -> simpan log. Juga rollback.
 */
class ProvisioningService
{
    public function __construct(
        private readonly Container $container,
        private readonly ONTDetector $detector,
        private readonly ONTManager $manager,
        private readonly AuditLogger $audit,
        private readonly MappingVerifier $verifier,
    ) {}

    public static function isProduction(): bool
    {
        return config('ontconfig.provisioning_mode') === 'production';
    }

    /** Server GenieACS milik ONT ini (S.NET bisa punya beberapa server). */
    private function acs(OntDevice $device): GenieACSService
    {
        return GenieACSService::forDevice($device);
    }

    /** @return array{ctx: DeviceContext, plan: ProvisioningPlan, blockers: list<string>} */
    public function prepare(OntDevice $device, ConfigurationRequest $req): array
    {
        $tree = $this->acs($device)->getDeviceDetails($device->genieacs_id);
        $ctx = DeviceContext::build($device, $tree, $this->detector->detect($tree));

        $plan = new ProvisioningPlan;
        if (ONTValidator::supported($ctx, $plan)) {
            $plan = $this->manager->driverFor($ctx->detection)->buildPlan($ctx, $req);
        }

        return ['ctx' => $ctx, 'plan' => $plan, 'blockers' => ONTValidator::productionBlockers($ctx, $plan)];
    }

    /** @return array<string, mixed> */
    public function preview(OntDevice $device, array $input): array
    {
        $p = $this->prepare($device, ConfigurationRequest::fromArray($input));
        $production = self::isProduction();

        return $p['plan']->toArray() + [
            'mode' => $production ? 'production' : 'simulation',
            'production_blockers' => $p['blockers'],
            'executable' => ! $p['plan']->hasErrors() && (! $production || $p['blockers'] === []),
        ];
    }

    /** Membuat job (idempoten) lalu mengantrekannya. */
    public function start(OntDevice $device, array $input, User $user, ?int $templateId = null): ConfigurationJob
    {
        $key = (string) $input['idempotency_key'];
        if ($existing = ConfigurationJob::where('idempotency_key', $key)->first()) {
            if ($existing->ont_device_id !== $device->id || $existing->user_id !== $user->id) {
                throw new ProvisioningException('Kunci permintaan sudah dipakai. Muat ulang wizard.', 'IDEMPOTENCY_CONFLICT', 409);
            }

            return $existing; // tombol APPLY ditekan dua kali -> job yang sama, tidak membuat WAN dobel
        }

        $active = ConfigurationJob::where('ont_device_id', $device->id)
            ->whereIn('status', [JobStatus::Pending, JobStatus::Processing, JobStatus::Verifying])->first();
        if ($active) {
            throw new ProvisioningException('Konfigurasi lain untuk ONT ini sedang berjalan. Tunggu sampai selesai.', 'JOB_RUNNING', 409);
        }

        $req = ConfigurationRequest::fromArray($input);
        $p = $this->prepare($device, $req);

        if ($p['plan']->hasErrors()) {
            throw new ProvisioningException('Konfigurasi tidak valid: '.implode(' ', $p['plan']->errors), 'VALIDATION_FAILED');
        }
        if (self::isProduction() && $p['blockers'] !== []) {
            throw new ProvisioningException('Mode produksi ditolak: '.implode(' ', $p['blockers']), 'NOT_VERIFIED');
        }

        $job = DB::transaction(function () use ($device, $user, $templateId, $req, $p, $key) {
            $job = ConfigurationJob::create([
                'ont_device_id' => $device->id,
                'user_id' => $user->id,
                'configuration_template_id' => $templateId,
                'service_type' => $req->serviceType,
                'request_payload' => $req->toArray(),
                'plan' => $p['plan']->toArray(),
                'status' => JobStatus::Pending,
                'is_simulation' => ! self::isProduction(),
                'idempotency_key' => $key,
            ]);

            $w = $req->wan;
            $slot = $w['slot'];
            $wan = $job->wanConfigurations()->create([
                'ont_device_id' => $device->id,
                'wan_name' => $p['plan']->summary['wan']['name'] ?? $w['name'],
                'slot' => $slot,
                'instance_path' => $w['mode'] === 'existing' ? $w['existing_path'] : null,
                'created_new' => $w['mode'] !== 'existing',
                'connection_type' => WANMapper::connectionType($req->serviceType, $slot),
                'vlan_id' => $w['vlan_id'],
                'vlan_priority' => $w['vlan_priority'],
                'service_list' => $req->serviceType === ServiceType::Pppoe ? 'INTERNET' : $w['service_list'],
                'nat_enabled' => $req->serviceType === ServiceType::Pppoe ? ($w['nat_enabled'] ?? true) : null,
                'pppoe_username' => $req->serviceType === ServiceType::Pppoe ? $w['pppoe_username'] : null,
            ]);

            $root = $p['ctx']->root();
            foreach ($req->binding['lan'] as $n) {
                $wan->bindings()->create(['interface_type' => 'lan', 'interface_index' => $n, 'interface_path' => "{$root}.LANDevice.1.LANEthernetInterfaceConfig.{$n}"]);
            }
            foreach ($req->binding['wlan'] as $n) {
                $wan->bindings()->create(['interface_type' => 'wlan', 'interface_index' => $n, 'interface_path' => "{$root}.LANDevice.1.WLANConfiguration.{$n}"]);
            }
            foreach ($req->ssids as $s) {
                $job->ssidConfigurations()->create([
                    'ssid_index' => $s['index'], 'ssid_name' => $s['ssid_name'], 'security_mode' => $s['security'],
                    'enabled' => $s['enabled'], 'hidden' => $s['hidden'],
                    // Password WiFi tidak disimpan di sini; hanya di request_payload terenkripsi sampai job selesai.
                ]);
            }

            return $job;
        });

        $this->history($job, 'queued', 'info', $job->is_simulation
            ? 'Job dibuat (MODE SIMULASI: tidak ada konfigurasi yang dikirim ke ONT).'
            : 'Job dibuat dan masuk antrean provisioning.');
        $this->audit->log('configuration.started', $job, ['serial_number' => $device->serial_number, 'service_type' => $req->serviceType->value, 'simulation' => $job->is_simulation]);

        RunProvisioningJob::dispatch($job->id);

        return $job;
    }

    /** Dijalankan oleh queue worker. */
    public function execute(ConfigurationJob $job): void
    {
        if ($job->status->isFinal() || $job->status !== JobStatus::Pending) {
            return;
        }
        $job->update(['status' => JobStatus::Processing, 'started_at' => now()]);

        try {
            $req = ConfigurationRequest::fromArray($job->request_payload);
            $job->is_simulation ? $this->executeSimulation($job, $req) : $this->executeProduction($job, $req);
        } catch (ProvisioningException $e) {
            $this->finish($job, JobStatus::Failed, $e->getMessage(), $e->errorCode);
        } catch (GenieACSException $e) {
            $this->finish($job, JobStatus::Failed, $e->getMessage(), $e->errorCode);
        } catch (\Throwable $e) {
            report($e);
            $this->finish($job, JobStatus::Failed, 'Terjadi kesalahan internal saat provisioning. Detail tercatat di log server.', 'INTERNAL_ERROR');
        } finally {
            // Hapus password dari payload setelah selesai (tidak diperlukan lagi).
            if (isset($req)) {
                $job->forceFill(['request_payload' => $req->withoutSecrets()])->save();
            }
        }
    }

    private function executeSimulation(ConfigurationJob $job, ConfigurationRequest $req): void
    {
        $p = $this->prepare($job->device, $req);
        if ($p['plan']->hasErrors()) {
            throw new ProvisioningException('Validasi ulang gagal: '.implode(' ', $p['plan']->errors), 'VALIDATION_FAILED');
        }

        $this->recordBackups($job, $p['ctx'], $p['plan']->allValues());
        foreach ($p['plan']->toArray()['steps'] as $step) {
            $this->history($job, 'simulated_'.$step['type'], 'info', 'SIMULASI: '.$step['description'], $step);
        }
        if ($p['blockers'] !== []) {
            $this->history($job, 'production_blockers', 'warning', 'Di mode produksi konfigurasi ini AKAN DITOLAK: '.implode(' ', $p['blockers']));
        }

        $this->finish($job, JobStatus::Success, 'Simulasi selesai. Tidak ada konfigurasi yang dikirim ke ONT.');
    }

    private function executeProduction(ConfigurationJob $job, ConfigurationRequest $req): void
    {
        $device = $job->device;
        $acs = $this->acs($device);
        $id = $device->genieacs_id;

        // 1. ONT harus online SEKARANG
        $online = $acs->getDeviceConnectionStatus($id);
        if (! $online['online']) {
            throw new ProvisioningException('ONT offline: '.$online['message'].' Konfigurasi tidak dikirim.', 'DEVICE_OFFLINE');
        }
        $this->history($job, 'online', 'success', 'ONT online, merespons Connection Request.');

        // 2. Baca ulang objek yang akan diubah agar backup memakai nilai terbaru
        foreach ($this->objectsToRefresh($req, $job->device->genieacs_id) as $object) {
            $acs->refreshDeviceParameters($id, $object);
        }

        // 3. Validasi ulang dengan data terbaru
        $p = $this->prepare($device, $req);
        if ($p['plan']->hasErrors()) {
            throw new ProvisioningException('Validasi ulang gagal: '.implode(' ', $p['plan']->errors), 'VALIDATION_FAILED');
        }
        if ($p['blockers'] !== []) {
            throw new ProvisioningException('Ditolak: '.implode(' ', $p['blockers']), 'NOT_VERIFIED');
        }
        $job->update(['plan' => $p['plan']->toArray()]);

        // 4. Backup nilai lama
        $this->recordBackups($job, $p['ctx'], $p['plan']->allValues());
        $this->history($job, 'backup', 'info', 'Nilai parameter sebelumnya disimpan untuk rollback.');

        // 5. Eksekusi langkah
        $markers = [];
        $createdWanPath = null;
        $applied = [];
        $taskIds = [];
        foreach ($p['plan']->steps as $step) {
            if ($step['type'] === 'add_object') {
                $parent = strtr($step['object'], $markers);
                $acs->refreshDeviceParameters($id, $parent);
                $before = $acs->getDeviceDetails($id)->instances($parent);

                $r = $acs->addObject($id, $parent);
                $taskIds[] = $r['task_id'];
                $this->assertTask($job, $r, 'ADD_OBJECT_FAILED', 'AddObject '.$parent);

                $acs->refreshDeviceParameters($id, $parent);
                $new = array_values(array_diff($acs->getDeviceDetails($id)->instances($parent), $before));
                if (count($new) !== 1) {
                    throw new ProvisioningException("AddObject {$parent} gagal: instance baru tidak ditemukan.", 'ADD_OBJECT_FAILED');
                }
                $marker = $step['assign'] === 'WCD' ? ProvisioningPlan::MARK_WCD : ProvisioningPlan::MARK_I;
                $markers[$marker] = (string) $new[0];
                if ($step['assign'] === 'I') {
                    $createdWanPath = "{$parent}.{$new[0]}";
                }

                ConfigurationBackup::create([
                    'configuration_job_id' => $job->id, 'parameter_path' => "{$parent}.{$new[0]}",
                    'old_value' => null, 'new_value' => '__created_object__', 'existed_before' => false, 'is_secret' => false,
                    'applied' => true,
                ]);
                $this->resolveBackupPaths($job, $markers);
                $this->history($job, 'add_object', 'success', "{$step['description']}: {$parent}.{$new[0]}");
            } else {
                $values = array_map(fn ($v) => ['path' => strtr($v['path'], $markers)] + $v, $step['values']);
                $r = $acs->setParameterValues($id, array_map(fn ($v) => [$v['path'], $v['value'], $v['type']], $values));
                $taskIds[] = $r['task_id'];
                $this->assertTask($job, $r, 'SET_VALUES_FAILED', $step['description']);
                array_push($applied, ...$values);
                // Hanya perubahan yang sudah diterapkan ONT yang boleh di-rollback.
                $job->backups()->whereIn('parameter_path', array_column($values, 'path'))->update(['applied' => true]);
                $this->history($job, 'set_values', 'success', $step['description'].' diterapkan ONT.', ['values' => Redactor::redact(array_map(fn ($v) => [$v['path'], $v['secret'] ? Redactor::MASK : $v['value']], $values))]);
            }
        }
        $job->update(['genieacs_task_ids' => array_values(array_filter($taskIds))]);

        if ($createdWanPath !== null) {
            $job->wanConfigurations()->update(['instance_path' => $createdWanPath]);
        }
        $this->resolveBackupPaths($job, $markers);

        // 6. Baca ulang & verifikasi
        $job->update(['status' => JobStatus::Verifying]);
        foreach (array_unique(array_map(fn ($v) => preg_replace('/\.[^.]+$/', '', $v['path']), $applied)) as $object) {
            $acs->refreshDeviceParameters($id, $object);
        }
        $tree = $acs->getDeviceDetails($id);
        $ctx = DeviceContext::build($device, $tree, $this->detector->detect($tree));
        $results = $this->manager->driverFor($ctx->detection)->verifyConfiguration($ctx, $applied);

        // Parameter yang terbukti tertulis & terbaca ulang cocok -> verified level "write" (otomatis).
        if ($ctx->model()) {
            $written = $this->verifier->verifyWrites($ctx->model(), $results, $device->serial_number, $job->uuid);
            if ($written !== []) {
                $this->history($job, 'auto_verified', 'info', count($written).' mapping parameter otomatis terverifikasi (ditulis & dibaca ulang cocok).', ['keys' => $written]);
            }
        }

        $bad = array_values(array_filter($results, fn ($r) => in_array($r['status'], ['mismatch', 'missing'], true)));
        $this->history($job, 'verify', $bad === [] ? 'success' : 'warning',
            $bad === [] ? 'Verifikasi: semua parameter cocok dengan yang dikirim.' : count($bad).' parameter tidak cocok setelah dibaca ulang.',
            ['results' => $results]);

        $bad === []
            ? $this->finish($job, JobStatus::Success, 'Konfigurasi berhasil diterapkan dan diverifikasi.')
            : $this->finish($job, JobStatus::Partial, 'Konfigurasi terkirim, tetapi sebagian parameter tidak cocok saat diverifikasi.', 'VERIFY_MISMATCH');
    }

    /**
     * Rollback: kembalikan nilai lama & hapus objek yang dibuat. Tidak pernah mengklaim
     * pemulihan penuh bila sebagian gagal.
     *
     * @return array{status: string, restored: int, failed: int, message: string}
     */
    public function rollback(ConfigurationJob $job, User $user): array
    {
        if (! in_array($job->status, [JobStatus::Success, JobStatus::Partial, JobStatus::Failed], true)) {
            throw new ProvisioningException('Rollback hanya bisa untuk job yang sudah selesai.', 'ROLLBACK_NOT_ALLOWED');
        }
        if ($job->histories()->where('event', 'rollback')->exists()) {
            throw new ProvisioningException('Job ini sudah pernah di-rollback.', 'ROLLBACK_DONE');
        }
        $backups = $job->backups()->get();
        if ($backups->isEmpty()) {
            throw new ProvisioningException('Tidak ada data backup untuk job ini.', 'NO_BACKUP');
        }

        if ($job->is_simulation) {
            $job->backups()->update(['restore_status' => 'simulated', 'restored_at' => now()]);
            $this->history($job, 'rollback', 'info', 'SIMULASI rollback: '.$backups->count().' parameter/objek akan dipulihkan. Tidak ada yang dikirim ke ONT.');
            $this->audit->log('configuration.rollback', $job, ['simulation' => true]);

            return ['status' => 'simulated', 'restored' => $backups->count(), 'failed' => 0, 'message' => 'Rollback disimulasikan.'];
        }

        // Cek ini SEBELUM menghubungi ONT: job yang gagal sebelum menulis apa pun tidak perlu di-rollback.
        if ($backups->where('applied', true)->isEmpty()) {
            $job->backups()->update(['restore_status' => 'not_applied']);
            throw new ProvisioningException('Tidak ada perubahan yang sempat diterapkan ke ONT, jadi tidak ada yang perlu di-rollback.', 'NOTHING_APPLIED');
        }

        $acs = $this->acs($job->device);
        $id = $job->device->genieacs_id;
        $online = $acs->getDeviceConnectionStatus($id);
        if (! $online['online']) {
            throw new ProvisioningException('ONT offline, rollback tidak bisa dijalankan sekarang.', 'DEVICE_OFFLINE');
        }

        $created = $backups->where('applied', true)->where('existed_before', false)->where('new_value', '__created_object__');
        $restorable = $backups->filter(fn (ConfigurationBackup $b) => $b->applied && $b->existed_before && $b->old_value !== null
            && ! $created->contains(fn ($c) => str_starts_with($b->parameter_path, $c->parameter_path.'.')));
        $skipped = $backups->diff($restorable)->diff($created);

        $restored = 0;
        $failed = 0;

        if ($restorable->isNotEmpty()) {
            $r = $acs->setParameterValues($id, $restorable->map(fn ($b) => [$b->parameter_path, $this->castBack($b->old_value, $b->old_type), $b->old_type ?: 'xsd:string'])->values()->all());
            $ok = $r['state'] === 'done';
            foreach ($restorable as $b) {
                $b->update(['restore_status' => $ok ? 'restored' : 'failed', 'restored_at' => now()]);
            }
            $ok ? $restored += $restorable->count() : $failed += $restorable->count();
        }

        // Hapus objek yang dibuat (urutan terbalik: instance terdalam dulu)
        foreach ($created->sortByDesc(fn ($c) => substr_count($c->parameter_path, '.'))->values() as $c) {
            $r = $acs->deleteObject($id, $c->parameter_path);
            $ok = $r['state'] === 'done';
            $c->update(['restore_status' => $ok ? 'restored' : 'failed', 'restored_at' => now()]);
            $ok ? $restored++ : $failed++;
            if ($ok) {
                // Objek induk terhapus -> parameter di dalamnya ikut hilang.
                $skipped->filter(fn ($b) => str_starts_with($b->parameter_path, $c->parameter_path.'.'))->each->update(['restore_status' => 'removed_with_object']);
            }
        }
        $skipped->whereNull('restore_status')->each(fn ($b) => $b->update(['restore_status' => $b->applied ? 'skipped' : 'not_applied']));

        $status = $failed === 0 ? 'complete' : ($restored > 0 ? 'partial' : 'failed');
        $message = match ($status) {
            'complete' => "Rollback selesai: {$restored} parameter/objek dipulihkan.",
            'partial' => "Rollback SEBAGIAN: {$restored} berhasil, {$failed} gagal dipulihkan.",
            default => 'Rollback gagal: tidak ada parameter yang berhasil dipulihkan.',
        };
        $this->history($job, 'rollback', $status === 'complete' ? 'success' : 'warning', $message);
        $this->audit->log('configuration.rollback', $job, ['status' => $status, 'restored' => $restored, 'failed' => $failed]);

        return compact('status', 'restored', 'failed', 'message');
    }

    /** @param list<array{path: string, value: mixed, type: string, key: string, secret: bool}> $values */
    private function recordBackups(ConfigurationJob $job, DeviceContext $ctx, array $values): void
    {
        foreach ($values as $v) {
            $param = str_contains($v['path'], '#') ? null : $ctx->tree->parameter($v['path']);
            ConfigurationBackup::create([
                'configuration_job_id' => $job->id,
                'parameter_path' => $v['path'],
                'old_value' => $param === null || $param['value'] === null ? null : $this->stringify($param['value']),
                'old_type' => $param['type'] ?? $v['type'],
                'new_value' => $this->stringify($v['value']),
                'existed_before' => $param !== null,
                'is_secret' => $v['secret'] || Redactor::isSecretKey($v['path']),
            ]);
        }
    }

    private function resolveBackupPaths(ConfigurationJob $job, array $markers): void
    {
        if ($markers === []) {
            return;
        }
        foreach ($job->backups()->where('parameter_path', 'like', '%#%')->get() as $b) {
            $b->update(['parameter_path' => strtr($b->parameter_path, $markers)]);
        }
    }

    /** @return list<string> */
    private function objectsToRefresh(ConfigurationRequest $req, string $deviceId): array
    {
        $root = 'InternetGatewayDevice';
        $objects = [];
        if ($req->wan['mode'] === 'existing' && $req->wan['existing_path']) {
            $objects[] = $req->wan['existing_path'];
        }
        foreach ($req->ssids as $s) {
            $objects[] = "{$root}.LANDevice.1.WLANConfiguration.{$s['index']}";
        }

        return array_values(array_unique($objects));
    }

    private function assertTask(ConfigurationJob $job, array $r, string $code, string $what): void
    {
        if ($r['state'] === 'done') {
            return;
        }
        $reason = $r['state'] === 'timeout'
            ? 'Task timeout: ONT tidak menjalankan task dalam batas waktu (task dibatalkan).'
            : 'ONT menolak ('.($r['fault']['code'] ?? '?').'): '.($r['fault']['message'] ?? 'fault tanpa pesan');
        $this->history($job, 'task_failed', 'error', "{$what} gagal. {$reason}");

        throw new ProvisioningException("{$what} gagal. {$reason}", $r['state'] === 'timeout' ? 'TASK_TIMEOUT' : $code);
    }

    private function finish(ConfigurationJob $job, JobStatus $status, string $message, ?string $code = null): void
    {
        $job->update([
            'status' => $status,
            'finished_at' => now(),
            'error_code' => $status === JobStatus::Success ? null : $code,
            'error_message' => $status === JobStatus::Success ? null : $message,
        ]);
        $this->history($job, 'finished', match ($status) {
            JobStatus::Success => 'success', JobStatus::Partial => 'warning', default => 'error',
        }, $message);
        $this->audit->log('configuration.finished', $job, ['status' => $status->value, 'error_code' => $code], $job->user_id);
    }

    public function history(ConfigurationJob $job, string $event, string $status, string $message, array $meta = []): void
    {
        $job->histories()->create([
            'event' => $event, 'status' => $status, 'message' => $message,
            'meta' => $meta === [] ? null : Redactor::redact($meta),
        ]);
    }

    private function stringify(mixed $v): ?string
    {
        return match (true) {
            $v === null => null,
            is_bool($v) => $v ? 'true' : 'false',
            default => (string) $v,
        };
    }

    private function castBack(?string $value, ?string $type): mixed
    {
        return match ($type) {
            'xsd:boolean' => in_array(strtolower((string) $value), ['1', 'true'], true),
            'xsd:int', 'xsd:unsignedInt' => (int) $value,
            default => $value,
        };
    }
}
