@extends('layouts.app')
@section('title', 'Daftar Router / NAS')
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">Router / NAS</h1>
        <p class="page-subtitle">Kelola router MikroTik yang terdaftar sebagai RADIUS client</p>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('routers.sync-radius') }}" class="d-inline" onsubmit="return confirm('Sinkronkan seluruh router NAS, voucher, dan akun PPPoE ke FreeRADIUS sekarang?');">
            @csrf
            <button type="submit" class="btn btn-outline-success"><i class="bi bi-arrow-repeat me-1"></i> Sinkronkan ke FreeRADIUS</button>
        </form>
        <a href="{{ route('routers.create') }}" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Tambah Router</a>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="card table-card">
    <div class="table-toolbar">
        <span class="fw-600">{{ $routers->count() }} Router Terdaftar</span>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari router...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr><th>#</th><th>Nama</th><th>IP / NAS Name</th><th>Lokasi</th><th>Status</th><th>Koneksi API</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($routers as $i => $r)
            <tr data-test-url="{{ route('routers.test', $r) }}">
                <td class="text-muted">{{ $i + 1 }}</td>
                <td>
                    <div class="fw-600">{{ $r->name }}</div>
                    @if ($r->genieAcsSetting)<small class="text-muted"><i class="bi bi-hdd-network me-1"></i>{{ $r->genieAcsSetting->name }}</small>@endif
                </td>
                <td class="font-mono">{{ $r->ip_address }}@if ($r->nas_ip && $r->nas_ip !== $r->ip_address)<div class="text-muted" style="font-size:.72rem;">NAS: {{ $r->nas_ip }}</div>@endif</td>
                <td class="text-muted">{{ $r->location ?: '-' }}</td>
                <td>@if ($r->status === 'active')<span class="badge bg-success">Aktif</span>@else<span class="badge bg-secondary">Nonaktif</span>@endif</td>
                <td>
                    <span class="router-status-badge badge bg-secondary"><span class="status-dot"></span>Cek...</span>
                    <span class="router-users-count text-muted ms-1" style="font-size:.73rem;"></span>
                </td>
                <td>
                    <div class="d-flex gap-1 flex-wrap">
                        <a href="{{ route('routers.edit', $r) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-outline-info btn-icon" title="Test API" onclick="testRouterApi(this.closest('tr'))"><i class="bi bi-lightning"></i></button>
                        <form method="POST" action="{{ route('routers.destroy', $r) }}" class="d-inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus router '{{ $r->name }}'? Voucher yang dikaitkan ke router ini akan terpengaruh." title="Hapus"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-router display-4 d-block mb-2"></i>Belum ada router. <a href="{{ route('routers.create') }}">Tambah sekarang</a></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@include('routers._test_script')
<script>document.querySelectorAll('tr[data-test-url]').forEach(tr => testRouterApi(tr));</script>
@endsection
