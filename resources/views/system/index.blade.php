@extends('layouts.app')
@section('title', 'Audit Log & Sistem')
@use('App\Services\Dashboard\DashboardStatsService', 'Stats')
@section('content')
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card"><div class="card-header">Informasi Sistem</div>
            <table class="table table-sm small mb-0"><tbody>
                @foreach ($info as $k => $v)<tr><th class="fw-normal text-body-secondary">{{ $k }}</th><td>{{ $v }}</td></tr>@endforeach
            </tbody></table>
            <div class="card-body small text-body-secondary border-top">
                Mode provisioning diatur di file <code>.env</code> (<code>PROVISIONING_MODE=simulation|production</code>), lalu jalankan <code>php artisan config:clear</code>.
                Sengaja tidak bisa diubah dari web agar tidak ada yang bisa mengaktifkan produksi secara tidak sengaja.
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span>Audit Log</span>
            <form class="ms-auto d-flex gap-2"><select class="form-select form-select-sm" name="action"><option value="">Semua aksi</option>
                @foreach ($actions as $a)<option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ Stats::describeAction($a) }}</option>@endforeach</select>
                <button class="btn btn-sm btn-secondary">Filter</button></form></div>
            <div class="table-responsive"><table class="table table-sm small align-middle mb-0">
                <thead><tr><th>Waktu</th><th>User</th><th>Aksi</th><th>Objek</th><th>IP</th><th>Detail</th></tr></thead>
                <tbody>
                @forelse ($logs as $l)
                    <tr>
                        <td class="text-nowrap">{{ $l->created_at?->format('d/m H:i:s') }}</td>
                        <td>{{ $l->user?->name ?? '-' }}</td>
                        <td>{{ Stats::describeAction($l->action) }}</td>
                        <td>{{ $l->subject_type }}{{ $l->subject_id ? ' #'.$l->subject_id : '' }}</td>
                        <td class="font-monospace">{{ $l->ip_address }}</td>
                        <td class="font-monospace text-break" style="max-width:22rem">{{ $l->changes ? \Illuminate\Support\Str::limit(json_encode($l->changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 160) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-body-secondary py-3">Belum ada log.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if ($logs->hasPages())<div class="card-footer">{{ $logs->links('pagination::bootstrap-5') }}</div>@endif
        </div>
    </div>
</div>
@endsection
