@extends('layouts.app')
@section('title', 'Log Aktivitas VPN WireGuard')
@php $routerIds = \App\Models\WgRouter::pluck('id')->flip(); @endphp
@section('page-header')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="{{ route('wireguard.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router</a>
        <h1 class="page-title mt-1"><i class="bi bi-journal-text me-2 text-primary"></i>Log Aktivitas WireGuard VPN</h1>
        <p class="page-subtitle mb-0">Riwayat penambahan peer, perubahan konfigurasi, dan aktivitas VPN</p>
    </div>
</div>
@endsection
@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent py-3"><span class="fw-bold fs-6">100 Log Aktivitas Terakhir</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th style="width: 170px;">Waktu</th><th>Aktivitas (Event)</th><th>Router Terkait</th><th>Rincian Detail</th></tr></thead>
            <tbody>
            @forelse ($logs as $l)
                <tr>
                    <td class="small text-muted font-monospace">{{ $l->created_at?->format('d/m/Y H:i:s') }}</td>
                    <td><span class="badge bg-primary-subtle text-primary fw-semibold">{{ $l->event }}</span></td>
                    <td>
                        @if ($l->router_name)
                            @if ($l->router_id && isset($routerIds[$l->router_id]))<a href="{{ route('wireguard.show', $l->router_id) }}" class="fw-bold text-decoration-none text-dark">{{ $l->router_name }}</a>
                            @else<span class="fw-semibold">{{ $l->router_name }}</span>@endif
                        @else<span class="text-muted">-</span>@endif
                    </td>
                    <td class="small text-muted font-monospace">{{ $l->details }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center py-5 text-muted"><i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>Belum ada catatan log aktivitas WireGuard.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
