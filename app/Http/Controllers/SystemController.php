<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\GenieAcsSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('view-audit');
        $f = $request->validate(['action' => ['nullable', 'string', 'max:100'], 'user' => ['nullable', 'integer']]);

        $logs = AuditLog::with('user:id,name')
            ->when($f['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', addcslashes($a, '%_').'%'))
            ->when($f['user'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->latest('id')->paginate(30)->withQueryString();

        return view('system.index', [
            'logs' => $logs,
            'filters' => $f,
            'info' => [
                'Mode provisioning' => strtoupper((string) config('ontconfig.provisioning_mode')),
                'Versi aplikasi' => '1.0.0',
                'Laravel / PHP' => app()->version().' / '.PHP_VERSION,
                'Database' => config('database.default'),
                'Queue' => config('queue.default'),
                'Job di antrean' => config('queue.default') === 'database' ? DB::table('jobs')->count() : '-',
                'Job gagal (worker)' => DB::table('failed_jobs')->count(),
                'Zona waktu' => config('app.timezone'),
                'GenieACS NBI' => GenieAcsSetting::active()?->nbi_url ?? '-',
                'Batas waktu task' => config('ontconfig.task_timeout_seconds').' detik',
            ],
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
