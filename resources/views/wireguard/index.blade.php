@extends('layouts.app')
@section('title', 'VPN WireGuard — Router Peers')
@php
    $online = $routers->filter(fn ($r) => \App\Services\WireGuard\WireGuardService::isConnected($peerStatus[$r->public_key] ?? null))->count();
    $totalRx = $routers->sum(fn ($r) => $peerStatus[$r->public_key]['rx'] ?? 0);
    $totalTx = $routers->sum(fn ($r) => $peerStatus[$r->public_key]['tx'] ?? 0);
@endphp
@section('page-header')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <h1 class="page-title"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>VPN WireGuard Hub</h1>
        <p class="page-subtitle mb-0">Manajemen Interkoneksi Router MikroTik &amp; Remote Access</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-success" id="btnSyncWg" onclick="wgSyncAll(this)" title="Sinkronkan seluruh router dari database ke WireGuard Linux"><i class="bi bi-arrow-repeat me-1"></i> Sinkronkan WireGuard</button>
        <a href="{{ route('wireguard.index', ['tab' => 'forwards']) }}" class="btn btn-outline-primary"><i class="bi bi-arrow-left-right me-1"></i> Port Forwarding</a>
        <a href="{{ route('wireguard.index', ['tab' => 'settings']) }}" class="btn btn-outline-secondary"><i class="bi bi-gear-fill me-1"></i> Pengaturan Server</a>
        <a href="{{ route('wireguard.create') }}" class="btn btn-primary"><i class="bi bi-plus-circle-fill me-1"></i> Tambah Router VPN</a>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-card blue h-100"><div class="stat-icon"><i class="bi bi-router"></i></div><div class="stat-value">{{ number_format($routers->count()) }}</div><div class="stat-label">Total Router Peer</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card green h-100"><div class="stat-icon"><i class="bi bi-broadcast"></i></div><div class="stat-value">{{ number_format($online) }}</div><div class="stat-label">Router Terhubung (Online)</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card teal h-100"><div class="stat-icon"><i class="bi bi-arrow-down-circle"></i></div><div class="stat-value">{{ format_bytes($totalRx) }}</div><div class="stat-label">Total Transfer RX</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-card orange h-100"><div class="stat-icon"><i class="bi bi-arrow-up-circle"></i></div><div class="stat-value">{{ format_bytes($totalTx) }}</div><div class="stat-label">Total Transfer TX</div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold fs-6">Daftar Peer Router MikroTik</span>
            <span class="badge bg-light text-dark border">{{ $routers->count() }} Router</span>
        </div>
        <form method="GET" class="d-flex gap-2" style="max-width: 320px;">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Cari router / IP..." value="{{ $search }}">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            @if ($search !== '')<a href="{{ route('wireguard.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>@endif
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th style="width: 50px;">Status</th><th>Nama Router</th><th>IP Tunnel</th><th>Subnet LAN</th><th>Traffic RX / TX</th><th>Last Handshake</th><th>Remote NAT</th><th class="text-end" style="width: 140px;">Aksi</th></tr></thead>
            <tbody>
            @forelse ($routers as $r)
                @php $st = $peerStatus[$r->public_key] ?? null; $isOnline = \App\Services\WireGuard\WireGuardService::isConnected($st); @endphp
                <tr>
                    <td class="text-center">
                        @if ($isOnline)<span class="badge bg-success-subtle text-success p-2 rounded-circle" title="Connected"><i class="bi bi-check-circle-fill"></i></span>
                        @else<span class="badge bg-secondary-subtle text-secondary p-2 rounded-circle" title="Offline"><i class="bi bi-dash-circle"></i></span>@endif
                    </td>
                    <td>
                        <a href="{{ route('wireguard.show', $r) }}" class="fw-bold text-decoration-none text-dark">{{ $r->name }}</a>
                        @if ($r->location)<div class="small text-muted"><i class="bi bi-geo-alt me-1"></i>{{ $r->location }}</div>@endif
                    </td>
                    <td><span class="badge bg-primary-subtle text-primary font-monospace fs-7">{{ $r->tunnel_ip }}</span></td>
                    <td>
                        @if ($r->lan_subnets)
                            @foreach (explode(',', $r->lan_subnets) as $sub)<span class="badge bg-light text-dark border font-monospace me-1">{{ trim($sub) }}</span>@endforeach
                        @else<span class="text-muted small">-</span>@endif
                    </td>
                    <td>
                        @if ($st)<div class="small font-monospace"><span class="text-success"><i class="bi bi-arrow-down-short"></i>{{ format_bytes($st['rx']) }}</span><span class="text-primary ms-1"><i class="bi bi-arrow-up-short"></i>{{ format_bytes($st['tx']) }}</span></div>
                        @else<span class="text-muted small">0 B</span>@endif
                    </td>
                    <td><span class="small {{ $isOnline ? 'text-success fw-semibold' : 'text-muted' }}">{{ ($st['handshake'] ?? 0) > 0 ? \Illuminate\Support\Carbon::createFromTimestamp($st['handshake'])->diffForHumans() : 'Belum pernah' }}</span></td>
                    <td>
                        @if ($r->port_forwards_count > 0)
                        <a href="{{ route('wireguard.index', ['tab' => 'forwards', 'router_id' => $r->id]) }}" class="badge bg-info-subtle text-info text-decoration-none"><i class="bi bi-arrow-left-right me-1"></i>{{ $r->port_forwards_count }} Port</a>
                        @else
                        <a href="{{ route('wireguard.index', ['tab' => 'forwards', 'router_id' => $r->id]) }}" class="text-muted small text-decoration-none">+ Tambah NAT</a>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="{{ route('wireguard.show', $r) }}" class="btn btn-outline-primary" title="Lihat Skrip &amp; Detail"><i class="bi bi-terminal"></i></a>
                            <a href="{{ route('wireguard.edit', $r) }}" class="btn btn-outline-secondary" title="Edit Router"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('wireguard.destroy', $r) }}" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin menghapus router WireGuard \'{{ addslashes($r->name) }}\'?\n\nKoneksi VPN router ini akan langsung diputus dan dihapus dari server.')">
                                @csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger rounded-start-0" title="Hapus Router"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center py-5 text-muted"><i class="bi bi-shield-slash fs-1 d-block mb-2 text-secondary"></i>Belum ada router WireGuard yang ditambahkan.
                    <div class="mt-2"><a href="{{ route('wireguard.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle me-1"></i> Tambah Router Sekarang</a></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@include('wireguard._tools')
@endsection
