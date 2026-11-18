@extends('layouts.app')
@section('title', 'Audit Log')
@section('page-header')
<div class="page-header">
    <div><h1 class="page-title">Audit Log</h1><p class="page-subtitle">Aktivitas admin selama 500 entri terakhir</p></div>
</div>
@endsection
@section('content')
<div class="card table-card">
    <div class="table-toolbar">
        <span class="fw-600">{{ $logs->count() }} Entri</span>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari log...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Target</th><th>IP</th><th>Detail</th></tr></thead>
            <tbody>
            @forelse ($logs as $l)
            <tr>
                <td style="font-size:.75rem;">{{ $l->created_at?->format('d/m/Y H:i') }}</td>
                <td class="fw-600">{{ $l->user?->username ?: ($l->user?->name ?? 'Sistem') }}</td>
                <td><span class="badge bg-primary">{{ $l->action }}</span></td>
                <td class="font-mono" style="font-size:.78rem;">{{ $l->subject_type ? $l->subject_type.' #'.$l->subject_id : '-' }}</td>
                <td class="font-mono text-muted" style="font-size:.72rem;">{{ $l->ip_address ?? '-' }}</td>
                <td style="font-size:.72rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;" title="{{ $l->changes ? json_encode($l->changes, JSON_UNESCAPED_UNICODE) : '' }}">{{ $l->changes ? \Illuminate\Support\Str::limit(json_encode($l->changes, JSON_UNESCAPED_UNICODE), 120) : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">Belum ada log</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
