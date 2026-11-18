<?php

namespace App\Services\Dashboard;

use App\Enums\JobStatus;
use App\Enums\Vendor;
use App\Models\AuditLog;
use App\Models\ConfigurationJob;
use App\Models\OntDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardStatsService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $threshold = now()->subSeconds((int) config('ontconfig.online_threshold_seconds'));

        $total = OntDevice::count();
        $recent = OntDevice::where('last_inform_at', '>=', $threshold)->count();

        $byVendor = OntDevice::query()
            ->selectRaw('vendor, COUNT(*) as total')
            ->groupBy('vendor')
            ->pluck('total', 'vendor');

        $vendors = [];
        foreach (Vendor::cases() as $vendor) {
            $vendors[$vendor->value] = ['label' => $vendor->label(), 'total' => (int) ($byVendor[$vendor->value] ?? 0)];
        }
        // Vendor NULL (belum dikenali) muncul dengan kunci '' pada pluck().
        $vendors['other'] = ['label' => 'Lainnya', 'total' => (int) ($byVendor[''] ?? 0)];

        // Statistik provisioning hanya menghitung job PRODUKSI (simulasi dipisah).
        $jobs = ConfigurationJob::where('is_simulation', false);

        return [
            'devices' => [
                'total' => $total,
                'online' => $recent,
                'offline' => $total - $recent,
                'online_basis' => 'Inform terakhir < '.(int) (config('ontconfig.online_threshold_seconds') / 60).' menit (data cache GenieACS)',
                'last_synced_at' => OntDevice::max('synced_at'),
            ],
            'vendors' => $vendors,
            'configurations' => [
                'success' => (clone $jobs)->where('status', JobStatus::Success)->count(),
                'failed' => (clone $jobs)->where('status', JobStatus::Failed)->count(),
                'partial' => (clone $jobs)->where('status', JobStatus::Partial)->count(),
                'running' => (clone $jobs)->whereIn('status', [JobStatus::Pending, JobStatus::Processing, JobStatus::Verifying])->count(),
                'simulation_total' => ConfigurationJob::where('is_simulation', true)->count(),
            ],
            'activity' => $this->activity(14),
            'provisioning_mode' => config('ontconfig.provisioning_mode'),
        ];
    }

    /**
     * Jumlah job sukses/gagal per hari, N hari terakhir (termasuk hari ini).
     *
     * @return array{labels: list<string>, success: list<int>, failed: list<int>}
     */
    public function activity(int $days): array
    {
        $start = now()->startOfDay()->subDays($days - 1);

        $rows = ConfigurationJob::query()
            ->where('is_simulation', false)
            ->where('created_at', '>=', $start)
            ->get(['status', 'created_at'])
            ->groupBy(fn (ConfigurationJob $job) => $job->created_at->toDateString());

        $labels = $success = $failed = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $items = $rows->get($day->toDateString(), collect());
            $labels[] = $day->translatedFormat('d M');
            $success[] = $items->where('status', JobStatus::Success)->count();
            $failed[] = $items->whereIn('status', [JobStatus::Failed, JobStatus::Partial])->count();
        }

        return compact('labels', 'success', 'failed');
    }

    /** @return Collection<int, AuditLog> */
    public function recentActivity(int $limit = 8): Collection
    {
        return AuditLog::with('user:id,name')->latest('id')->limit($limit)->get();
    }

    /** @return Collection<int, ConfigurationJob> */
    public function lastConfigured(int $limit = 5): Collection
    {
        return ConfigurationJob::with(['device:id,serial_number,vendor,product_class', 'user:id,name'])
            ->whereIn('status', [JobStatus::Success, JobStatus::Partial, JobStatus::Failed])
            ->latest('finished_at')
            ->limit($limit)
            ->get();
    }

    public static function describeAction(string $action): string
    {
        return [
            'auth.login' => 'Login',
            'auth.logout' => 'Logout',
            'auth.login_failed' => 'Login gagal',
            'auth.login_blocked_inactive' => 'Login ditolak (akun nonaktif)',
            'genieacs.settings_updated' => 'Mengubah pengaturan GenieACS',
            'genieacs.test_connection' => 'Tes koneksi GenieACS',
            'ont.search' => 'Mencari ONT',
            'ont.online_check' => 'Cek online ONT',
            'ont.refresh' => 'Refresh parameter ONT',
            'ont.sync' => 'Sinkronisasi ONT dari GenieACS',
            'ont.discover' => 'Discovery parameter WAN (objek sementara)',
            'configuration.started' => 'Memulai konfigurasi ONT',
            'configuration.finished' => 'Konfigurasi ONT selesai',
            'configuration.rollback' => 'Rollback konfigurasi',
            'template.created' => 'Membuat template', 'template.updated' => 'Mengubah template', 'template.deleted' => 'Menghapus template',
            'template.copied' => 'Menyalin template', 'template.default' => 'Mengatur template default',
            'model.created' => 'Menambah model ONT',
            'model.auto_registered' => 'Model ONT didaftarkan otomatis', 'model.auto_verified' => 'Parameter ONT terverifikasi otomatis', 'model.updated' => 'Mengubah model ONT',
            'model.mapping_saved' => 'Menyimpan mapping parameter', 'model.mapping_deleted' => 'Menghapus mapping parameter',
            'user.created' => 'Membuat user', 'user.updated' => 'Mengubah user', 'user.deleted' => 'Menghapus user', 'user.deactivated' => 'Menonaktifkan user',
        ][$action] ?? $action;
    }

    public static function since(?Carbon $time): string
    {
        return $time ? $time->diffForHumans() : '-';
    }
}
