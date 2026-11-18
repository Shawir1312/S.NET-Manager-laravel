@extends('layouts.app')
@section('title', 'Dashboard')
@php($rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.'))
@php($isAdmin = auth()->user()->isAdmin())
@section('page-header')
<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1 class="page-title m-0">Dashboard</h1>
        <p class="page-subtitle mb-0">Ringkasan status Hotspot, Broadband PPPoE, dan VPN WireGuard</p>
    </div>
    @can('operate')
        <div class="d-flex gap-2 align-items-center">
            <a href="{{ route('vouchers.create') }}" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Generate Voucher</a>
        </div>
    @endcan
</div>
@endsection

@section('content')
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 {{ $isAdmin ? 'col-xl-3' : 'col-xl-4' }}">
        <div class="card shadow-sm border-0 border-start border-4 border-primary h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-people me-1 text-primary"></i> {{ $isAdmin ? 'Pelanggan Broadband PPPoE' : 'Pelanggan Rumahan' }}</div>
                <div class="fs-4 fw-bold mt-1">{{ number_format($pppoe->total) }} <span class="fs-6 fw-normal text-muted">Pelanggan</span></div>
                <div class="small mt-1"><span class="badge bg-success me-1">🟢 {{ (int) $pppoe->active }} Aktif</span><span class="badge bg-danger">🔴 {{ (int) $pppoe->isolated }} Isolir</span></div>
            </div>
            @can('operate')<a href="{{ route('customers.index') }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-right"></i></a>@endcan
        </div></div>
    </div>
    <div class="col-12 col-md-6 {{ $isAdmin ? 'col-xl-3' : 'col-xl-4' }}">
        <div class="card shadow-sm border-0 border-start border-4 border-success h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-wallet2 me-1 text-success"></i> Tagihan PPPoE Bulan Ini</div>
                <div class="fs-4 fw-bold text-success mt-1">{{ $rp($pppoePaidMonth) }}</div>
                <div class="small text-muted mt-1">Periode {{ now()->translatedFormat('F Y') }}</div>
            </div>
            @can('operate')<a href="{{ route('payments.index') }}" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-right"></i></a>@endcan
        </div></div>
    </div>
    <div class="col-12 col-md-6 {{ $isAdmin ? 'col-xl-3' : 'col-xl-4' }}">
        <div class="card shadow-sm border-0 border-start border-4 border-warning h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-hdd-network me-1 text-warning"></i> ONT GenieACS</div>
                <div class="fs-4 fw-bold mt-1">{{ $ontOnline }} / {{ $ontTotal }} <span class="fs-6 fw-normal text-muted">Online</span></div>
                <div class="small text-muted mt-1">Total ONT: {{ number_format($ontTotal) }}</div>
            </div>
            @can('operate')<a href="{{ route('ont-monitor.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-arrow-right"></i></a>@endcan
        </div></div>
    </div>
    @if ($isAdmin)
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card shadow-sm border-0 border-start border-4 border-info h-100"><div class="card-body p-3 d-flex justify-content-between align-items-center">
            <div>
                <div class="text-uppercase fw-bold text-muted small"><i class="bi bi-shield-lock me-1 text-info"></i> VPN WireGuard Hub</div>
                <div class="fs-4 fw-bold mt-1">{{ $wgOnline }} / {{ $wgTotal }} <span class="fs-6 fw-normal text-muted">Router Online</span></div>
                <div class="small text-muted mt-1"><i class="bi bi-arrow-left-right me-1"></i> {{ $wgForwards }} Port Forwarding Aktif</div>
            </div>
            <a href="{{ route('wireguard.index') }}" class="btn btn-outline-info btn-sm"><i class="bi bi-arrow-right"></i></a>
        </div></div>
    </div>
    @endif
</div>

<div class="row g-3 mb-4">
    @foreach ([
        ['blue', 'bi-router', $routers->count(), 'Total Router'],
        ['green', 'bi-wifi', $activeSessions, 'User Aktif'],
        ['teal', 'bi-ticket-perforated', $vouchers['unused'] ?? 0, 'Voucher Tersedia'],
        ['orange', 'bi-ticket-detailed', $vouchers['active'] ?? 0, 'Voucher Aktif'],
        ['red', 'bi-x-circle', $vouchers['expired'] ?? 0, 'Kadaluarsa'],
        ['purple', 'bi-currency-dollar', $todaySales->cnt, 'Terjual Hari Ini'],
    ] as [$color, $icon, $value, $label])
        <div class="col-6 col-md-4 col-lg-2">
            <div class="stat-card {{ $color }} h-100">
                <div class="stat-icon"><i class="bi {{ $icon }}"></i></div>
                <div class="stat-value">{{ number_format($value) }}</div>
                <div class="stat-label">{{ $label }}</div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-8 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-router"></i> Status Router</h5>
                @can('manage-settings')<a href="{{ route('routers.index') }}" class="btn btn-sm btn-outline-primary">Kelola Router</a>@endcan
            </div>
            <div class="card-body">
                @if ($routers->isEmpty())
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-router display-4 d-block mb-2"></i><p>Belum ada router terdaftar.</p>
                        @can('manage-settings')<a href="{{ route('routers.create') }}" class="btn btn-primary btn-sm">+ Tambah Router</a>@endcan
                    </div>
                @else
                    <div class="row g-3">
                        @foreach ($routers as $router)
                            @php($seen = $router->last_seen && $router->last_seen->gt(now()->subMinutes(15)))
                            <div class="col-sm-6 col-md-4">
                                <div class="router-card {{ $router->isActive() ? ($seen ? 'online' : '') : 'offline' }}">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <div class="router-name">{{ $router->name }}</div>
                                            <div class="router-ip">{{ $router->ip_address }}</div>
                                        </div>
                                        <span class="badge {{ $router->isActive() ? 'bg-success' : 'bg-secondary' }}"><span class="status-dot {{ $router->isActive() ? 'online' : 'offline' }}"></span>{{ $router->isActive() ? 'Aktif' : 'Nonaktif' }}</span>
                                    </div>
                                    @if ($router->location)<div class="text-muted" style="font-size:.72rem;"><i class="bi bi-geo-alt me-1"></i>{{ $router->location }}</div>@endif
                                    <div class="router-users mt-2"><i class="bi bi-wifi me-1"></i>{{ $activeByRouter[$router->id] ?? 0 }} user aktif</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-graph-up"></i> Penjualan 7 Hari Terakhir</h5></div>
            <div class="card-body" style="height: 300px; position: relative;"><canvas id="salesChart"></canvas></div>
        </div>
    </div>

    <div class="col-12 col-lg-4 d-flex flex-column gap-3">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-cash-stack"></i> Ringkasan Penjualan</h5></div>
            <div class="card-body p-0"><div class="row g-0">
                @foreach (['Hari Ini' => $todaySales, 'Bulan Ini' => $monthSales] as $label => $s)
                    <div class="col-6 {{ $loop->first ? 'border-end' : '' }} d-flex flex-column align-items-center justify-content-center text-center p-3">
                        <div class="text-muted mb-2" style="font-size:0.8rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;">{{ $label }}</div>
                        <div class="mb-1" style="font-size:2rem;font-weight:800;color:var(--blue);line-height:1;">{{ $s->cnt }} <span style="font-size:0.8rem;font-weight:normal;color:#6c757d">pcs</span></div>
                        <div style="font-size:1.15rem;font-weight:700;color:var(--red);">{{ $rp($s->total) }}</div>
                    </div>
                @endforeach
            </div></div>
            @can('operate')
                <div class="card-footer bg-transparent border-top-0 pt-0 pb-3"><a href="{{ route('reports.sales') }}" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-bar-chart me-1"></i>Lihat Laporan Lengkap</a></div>
            @endcan
        </div>

        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-shop"></i> Pendapatan per Cabang</h5></div>
            <div class="card-body p-0"><div class="list-group list-group-flush">
                @forelse ($routerSales as $rs)
                    <div class="list-group-item d-flex justify-content-between align-items-center p-3">
                        <div>
                            <h6 class="mb-1" style="font-size:0.9rem; font-weight:700; color:var(--blue);">{{ $rs['name'] }}</h6>
                            <div class="text-muted" style="font-size:0.75rem;">Hari ini: <strong>{{ $rp($rs['today']) }}</strong></div>
                        </div>
                        <div class="text-end">
                            <div style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#6c757d; margin-bottom:2px;">Bulan Ini</div>
                            <div style="font-size:1.05rem; font-weight:800; color:var(--red); line-height:1;">{{ $rp($rs['month']) }}</div>
                        </div>
                    </div>
                @empty
                    <div class="list-group-item text-center text-muted py-4">Belum ada cabang/router</div>
                @endforelse
            </div></div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title"><i class="bi bi-clock-history"></i> Generate Terakhir</h5>
                @can('operate')<a href="{{ route('vouchers.index') }}" class="btn btn-sm btn-outline-primary">Lihat Semua</a>@endcan
            </div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0">
                <thead><tr><th>Batch</th><th>Profil</th><th>Sisa</th><th>Router</th><th>Waktu</th></tr></thead>
                <tbody>
                @forelse ($recentBatches as $b)
                    <tr>
                        <td><a href="{{ route('vouchers.index', ['batch_id' => $b->batch_id, 'status' => 'unused']) }}" class="font-mono fw-600 text-blue" style="font-size:.73rem;">{{ $b->batch_id }}</a></td>
                        <td style="font-size:.78rem;">{{ $b->profile?->name ?? '-' }}</td>
                        <td><span class="badge bg-success" title="Total dibuat: {{ $b->qty }}">{{ (int) $b->sisa }} sisa</span></td>
                        <td style="font-size:.75rem;">{{ $b->router?->name ?? 'Semua' }}</td>
                        <td style="font-size:.72rem;color:var(--gray-500);">{{ \Carbon\Carbon::parse($b->created_at)->format('d/m H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-3">Belum ada data</td></tr>
                @endforelse
                </tbody>
            </table></div></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const chartData = @json($chart);
    const ctx = document.getElementById('salesChart');
    if (!ctx || typeof Chart === 'undefined') return;
    const g = ctx.getContext('2d');
    const revGrad = g.createLinearGradient(0, 0, 0, 300); revGrad.addColorStop(0, 'rgba(198,40,40,0.25)'); revGrad.addColorStop(1, 'rgba(198,40,40,0)');
    const barGrad = g.createLinearGradient(0, 0, 0, 300); barGrad.addColorStop(0, 'rgba(21,101,192,0.95)'); barGrad.addColorStop(1, 'rgba(21,101,192,0.35)');
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const tick = isDark ? '#adb5bd' : '#6c757d';
    new Chart(g, {
        type: 'bar',
        data: {
            labels: chartData.map(d => new Date(d.day).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short' })),
            datasets: [
                { label: 'Voucher Terjual', data: chartData.map(d => +d.cnt), backgroundColor: barGrad, borderRadius: { topLeft: 8, topRight: 8 }, borderSkipped: false, yAxisID: 'y', order: 2 },
                { label: 'Pendapatan (Rp)', data: chartData.map(d => +d.revenue), type: 'line', borderColor: '#C62828', backgroundColor: revGrad, borderWidth: 2.5,
                  pointBackgroundColor: '#fff', pointBorderColor: '#C62828', pointBorderWidth: 2, pointRadius: 5, tension: 0.45, fill: true, yAxisID: 'y2', order: 1 },
            ],
        },
        options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'top', labels: { usePointStyle: true, color: isDark ? '#dee2e6' : '#343a40' } },
                tooltip: { callbacks: { label: c => c.dataset.label.includes('Pendapatan') ? ' Rp ' + parseInt(c.parsed.y).toLocaleString('id-ID') : ' ' + c.parsed.y + ' pcs' } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: tick } },
                y: { beginAtZero: true, ticks: { precision: 0, color: tick }, grid: { color: isDark ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.05)' } },
                y2: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { color: tick, callback: v => 'Rp ' + (v / 1000) + 'k' } },
            },
        },
    });
})();
</script>
@endpush
