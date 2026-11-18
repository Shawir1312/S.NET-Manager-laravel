@extends('layouts.app')
@section('title', 'Profil / Paket')
@php $unit = fn ($u) => ['minutes' => 'Menit', 'hours' => 'Jam', 'days' => 'Hari'][$u] ?? $u; @endphp
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">Profil / Paket</h1>
        <p class="page-subtitle">Kelola paket internet (mapping ke atribut RADIUS)</p>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('hotspot-profiles.sync-all') }}" class="m-0" onsubmit="return confirm('Sinkronkan durasi (Session-Timeout), kuota, dan rate limit seluruh profil ke semua voucher yang belum dipakai di FreeRADIUS?')">
            @csrf
            <button type="submit" class="btn btn-outline-primary" title="Terapkan durasi & limit profil ke seluruh voucher yang belum dipakai"><i class="bi bi-arrow-repeat me-1"></i> Sinkronkan Semua Voucher</button>
        </form>
        <a href="{{ route('hotspot-profiles.create') }}" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Tambah Profil</a>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-600">{{ $profiles->count() }} Profil</span>
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <select name="router_id" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
                    <option value="">Semua Cabang / Router</option>
                    @foreach ($all_routers as $rt)<option value="{{ $rt->id }}" @selected((int) request('router_id') === $rt->id)>{{ $rt->name }}</option>@endforeach
                </select>
            </form>
        </div>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari profil...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr><th>Nama Profil</th><th>Masa Aktif</th><th>Durasi Pakai</th><th>Kuota</th><th>Rate Limit</th><th>Harga</th><th>Router</th><th>Status</th><th>Voucher</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($profiles as $p)
            <tr>
                <td><div class="fw-bold">{{ $p->name }}</div>@if ($p->display_name)<small class="text-muted">{{ $p->display_name }}</small>@endif</td>
                <td>{{ $p->validity_value }} {{ $unit($p->validity_unit ?? 'days') }}</td>
                <td>@if ((int) $p->duration_value === 0)<span class="badge bg-secondary">Unlimited</span>@else{{ $p->duration_value }} {{ $unit($p->duration_unit ?? 'hours') }}@endif</td>
                <td>@if ($p->quota_mb > 0){{ format_bytes($p->quota_mb * 1048576) }}@else<span class="text-muted">Unlimited</span>@endif</td>
                <td class="font-mono"><span class="text-primary">↑{{ $p->rate_up ?: '0' }}</span> / <span class="text-success">↓{{ $p->rate_down ?: '0' }}</span></td>
                <td>
                    <div class="fw-bold">{{ format_price($p->price) }}</div>
                    @if (! $p->include_in_sales)
                    <span class="badge bg-secondary-subtle text-secondary border mt-1" style="font-size:.68rem;" title="Tidak dicatat ke data/laporan penjualan"><i class="bi bi-cart-x me-1"></i>Non-Penjualan</span>
                    @else
                    <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size:.68rem;" title="Dicatat ke data & laporan penjualan"><i class="bi bi-cart-check me-1"></i>Masuk Penjualan</span>
                    @endif
                    @if ($p->reseller_percent > 0)<br><small class="text-success"><i class="bi bi-tag"></i> Reseller: {{ (float) $p->reseller_percent }}%</small>@endif
                </td>
                <td>@if ($p->router_id){{ $p->router?->name }}@else<span class="text-muted">Semua Router</span>@endif</td>
                <td>@if ($p->is_active)<span class="badge bg-success">Aktif</span>@else<span class="badge bg-secondary">Nonaktif</span>@endif</td>
                <td><span class="badge bg-primary">{{ $p->voucher_count }}</span></td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="{{ route('hotspot-profiles.edit', $p) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                        <a href="{{ route('vouchers.create', ['profile_id' => $p->id]) }}" class="btn btn-sm btn-outline-success btn-icon" title="Generate Voucher"><i class="bi bi-plus-circle"></i></a>
                        <form method="POST" action="{{ route('hotspot-profiles.destroy', $p) }}" class="d-inline">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus profil '{{ $p->name }}'?" title="Hapus"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="10" class="text-center text-muted py-4">Belum ada profil.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
