<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Pengaturan umum (pengumuman/maintenance portal) dan backup database. */
class SettingController extends Controller
{
    public function general(): View
    {
        Gate::authorize('manage-settings');

        return view('settings.general', [
            'announcements' => Announcement::latest('id')->get(),
            'radiusStatus' => $this->radiusStatus(),
            'tableCounts' => collect(['radcheck', 'radreply', 'radacct', 'nas', 'routers', 'profiles', 'vouchers', 'pppoe_customers', 'users'])
                ->mapWithKeys(fn ($t) => [$t => rescue(fn () => DB::table($t)->count(), 0, false)]),
        ]);
    }

    /** Status service FreeRADIUS (hanya terdeteksi di server Linux dengan systemctl). @return array<string, mixed> */
    private function radiusStatus(): array
    {
        $run = fn (array $cmd) => PHP_OS_FAMILY === 'Linux' ? trim(Process::timeout(5)->run($cmd)->output()) : '';
        $state = $run(['systemctl', 'is-active', 'freeradius']) ?: 'unknown';
        $udp = $run(['ss', '-lun']);

        return [
            'service_name' => 'freeradius', 'is_active' => $state === 'active', 'status_label' => strtoupper($state),
            'pid' => $run(['systemctl', 'show', '-p', 'MainPID', '--value', 'freeradius']) ?: null,
            'port_1812' => str_contains($udp, ':1812'), 'port_1813' => str_contains($udp, ':1813'),
            'version' => Str::limit(strtok($run(['freeradius', '-v']) ?: '', "\n") ?: '', 40, ''),
        ];
    }

    public function restartRadius(AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');
        if (PHP_OS_FAMILY !== 'Linux') {
            return back()->withErrors(['radius' => 'Server ini bukan Linux; restart FreeRADIUS lewat terminal: sudo systemctl restart freeradius']);
        }
        $r = Process::timeout(30)->run(['sudo', '-n', 'systemctl', 'restart', 'freeradius']);
        $audit->log('service.restart', null, ['service' => 'freeradius', 'ok' => $r->successful()]);

        return $r->successful() ? back()->with('status', 'Service FreeRADIUS berhasil direstart.')
            : back()->withErrors(['radius' => 'Gagal restart FreeRADIUS via web ('.trim($r->errorOutput()).'). Jalankan di SSH: sudo systemctl restart freeradius']);
    }

    public function storeAnnouncement(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate([
            'type' => ['required', 'in:info,warning,success,release,maintenance'],
            'title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:5000'],
            'start_at' => ['nullable', 'date'], 'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);
        Announcement::create($d + ['is_active' => true, 'show_on_login' => true, 'maintenance_mode' => $d['type'] === 'maintenance', 'created_by' => $request->user()->id]);

        return back()->with('status', 'Pengumuman ditambahkan.');
    }

    public function toggleAnnouncement(Announcement $announcement): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return back()->with('status', 'Status pengumuman diubah.');
    }

    public function destroyAnnouncement(Announcement $announcement): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $announcement->delete();

        return back()->with('status', 'Pengumuman dihapus.');
    }

    public function backup(BackupService $backups): View
    {
        Gate::authorize('manage-settings');

        return view('settings.backup', [
            'files' => $backups->list(), 'radStatus' => $this->radiusStatus(),
            'statVouchers' => DB::table('vouchers')->whereIn('status', ['unused', 'active'])->count(),
            'statPppoe' => DB::table('pppoe_customers')->count(), 'statRouters' => DB::table('routers')->where('status', 'active')->count(),
            'statRadcheck' => DB::table('radcheck')->distinct()->count('username'),
            'maxUpload' => ini_get('upload_max_filesize'), 'maxPost' => ini_get('post_max_size'), 'memLimit' => ini_get('memory_limit'),
        ]);
    }

    public function createBackup(BackupService $backups, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('manage-settings');
        try {
            $name = $backups->create();
        } catch (RuntimeException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }
        $audit->log('backup.created', null, ['file' => basename($name)]);

        return back()->with('status', 'Backup dibuat: '.basename($name));
    }

    public function downloadBackup(string $file, BackupService $backups, AuditLogger $audit): BinaryFileResponse
    {
        Gate::authorize('manage-settings');
        $audit->log('backup.downloaded', null, ['file' => $file]);

        return response()->download($backups->path($file));
    }

    /** Audit log aktivitas admin, 500 entri terakhir (port settings/audit_log.php). */
    public function audit(): View
    {
        Gate::authorize('view-audit');

        return view('settings.audit', ['logs' => AuditLog::with('user:id,name,username')->latest('id')->limit(500)->get()]);
    }
}
