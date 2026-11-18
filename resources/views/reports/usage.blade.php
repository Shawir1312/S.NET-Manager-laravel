@extends('layouts.app')
@section('title', 'Laporan Pemakaian Data')
@section('page-header')
@endsection
@section('content')
@include('partials.errors')
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="page-title"><i class="bi bi-bar-chart-line text-primary me-2"></i>Laporan Pemakaian Data</h1>
        <p class="page-subtitle mb-0">Akumulasi statistik pemakaian data bulanan dari FreeRADIUS (radacct)</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('reports.usage.export', ['mode' => $mode, 'month' => $filter_month, 'from' => $filter_from, 'to' => $filter_to, 'router_id' => $filter_router ?: null]) }}"
           class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-download"></i> Export CSV
        </a>
    </div>
</div>

<!-- FILTER CARD (MODE BULANAN & RENTANG TANGGAL) -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center">
            <input type="hidden" name="mode" id="filter_mode_input" value="{{ $mode }}">

            @if ($mode === 'month')
            <!-- Mode Pilihan Bulan -->
            <div class="col-12 col-md-auto d-flex align-items-center gap-1">
                <a href="{{ route('reports.usage', ['mode' => 'month', 'month' => $prev_month, 'router_id' => $filter_router ?: null]) }}" class="btn btn-outline-secondary btn-sm" title="Bulan Sebelumnya">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <select name="month" class="form-select form-select-sm fw-bold border-primary shadow-none" style="min-width: 190px;" onchange="this.form.submit()">
                    @foreach ($month_options as $mVal => $mLbl)
                    <option value="{!! $mVal !!}" {!! $filter_month === $mVal ? 'selected' : '' !!}>
                        📅 {{ $mLbl }}
                    </option>
                    @endforeach
                </select>
                @if ($filter_month !== date('Y-m'))
                <a href="{{ route('reports.usage', ['mode' => 'month', 'month' => $next_month, 'router_id' => $filter_router ?: null]) }}" class="btn btn-outline-secondary btn-sm" title="Bulan Berikutnya">
                    <i class="bi bi-chevron-right"></i>
                </a>
                @endif
            </div>
            @else
            <!-- Mode Rentang Tanggal Manual -->
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-0">Dari</label>
                <input type="date" class="form-control form-control-sm" name="from" value="{{ $filter_from }}">
            </div>
            <div class="col-6 col-md-auto">
                <label class="form-label small text-muted mb-0">Sampai</label>
                <input type="date" class="form-control form-control-sm" name="to" value="{{ $filter_to }}">
            </div>
            @endif

            <!-- Filter Router -->
            <div class="col-12 col-md-auto">
                <select class="form-select form-select-sm shadow-none" name="router_id" onchange="this.form.submit()">
                    <option value="">🌐 Semua Router</option>
                    @foreach ($all_routers as $r)
                    <option value="{!! $r['id'] !!}" {!! $filter_router == $r['id'] ? 'selected' : '' !!}>{{ $r['name'] }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Tombol Aksi & Toggle Mode -->
            <div class="col-auto ms-auto d-flex align-items-center gap-2">
                @if ($mode === 'range')
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="{{ route('reports.usage', ['mode' => 'month']) }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-calendar-month me-1"></i>Pilih Mode Bulanan
                </a>
                @else
                <a href="{{ route('reports.usage', ['mode' => 'range', 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString(), 'router_id' => $filter_router ?: null]) }}" class="btn btn-outline-secondary btn-sm" title="Ganti ke input rentang tanggal bebas">
                    <i class="bi bi-calendar3 me-1"></i>Rentang Bebas
                </a>
                @endif
            </div>
        </form>
    </div>
</div>

<!-- KARTU STATISTIK PEMAKAIAN (PERIODE BULAN TERPILIH) -->
<div class="row g-3 mb-4">
    <!-- Total Pemakaian (Download + Upload) -->
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 position-relative overflow-hidden" style="background: linear-gradient(135deg, #1E3A8A 0%, #3B82F6 100%); color: white; border-radius: 12px;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="text-uppercase fw-semibold" style="font-size: 0.72rem; opacity: 0.85; letter-spacing: 0.5px;">Total Pemakaian (Tx+Rx)</div>
                        <div class="h2 fw-bold mb-1 mt-1 font-mono">{!! format_bytes((float)$summary['total_bytes']) !!}</div>
                        <div class="small opacity-75" style="font-size: 0.75rem;">
                            Periode: <strong>{{ $mode === 'month' ? $selected_month_label : date('d M', strtotime($filter_from)) . ' - ' . date('d M Y', strtotime($filter_to)) }}</strong>
                        </div>
                    </div>
                    <div style="font-size: 2rem; opacity: 0.35;"><i class="bi bi-activity"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Download (Tx) -->
    <div class="col-6 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 usage-card-tx" style="border-radius: 12px; border-left: 4px solid #16A34A !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-success" style="font-size: 0.72rem;">Total Download (Tx)</span>
                    <i class="bi bi-arrow-down-circle-fill text-success fs-5"></i>
                </div>
                <div class="h3 fw-bold text-dark font-mono mb-1">{!! format_bytes((float)$summary['total_dl']) !!}</div>
                <div class="text-muted" style="font-size: 0.75rem;">Bandwidth keluar ke pelanggan</div>
            </div>
        </div>
    </div>

    <!-- Total Upload (Rx) -->
    <div class="col-6 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 usage-card-rx" style="border-radius: 12px; border-left: 4px solid #2563EB !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-primary" style="font-size: 0.72rem;">Total Upload (Rx)</span>
                    <i class="bi bi-arrow-up-circle-fill text-primary fs-5"></i>
                </div>
                <div class="h3 fw-bold text-dark font-mono mb-1">{!! format_bytes((float)$summary['total_ul']) !!}</div>
                <div class="text-muted" style="font-size: 0.75rem;">Bandwidth masuk dari pelanggan</div>
            </div>
        </div>
    </div>

    <!-- Waktu Online & Sesi -->
    @php $avg_online_secs = ((int)$summary['total_users'] > 0) ? (int)round((int)$summary['total_secs'] / (int)$summary['total_users']) : 0; @endphp
    <div class="col-12 col-sm-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100 usage-card-time" style="border-radius: 12px; border-left: 4px solid #D97706 !important;">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-uppercase fw-bold text-warning" style="font-size: 0.72rem;">
                        Total Jam Online (Akumulasi)
                        <i class="bi bi-info-circle ms-1 text-muted" style="cursor: pointer;" title="Penjumlahan total waktu online dari semua klien yang terhubung bersamaan pada periode ini. Rata-rata per klien = Total waktu dibagi jumlah klien."></i>
                    </span>
                    <i class="bi bi-clock-history text-warning fs-5"></i>
                </div>
                <div class="h4 fw-bold text-dark mb-1 font-mono">{!! format_durasi_clean((int)$summary['total_secs']) !!}</div>
                <div class="text-muted d-flex align-items-center justify-content-between mt-1" style="font-size: 0.75rem;">
                    <span>Rata-rata: <strong>{!! format_durasi_clean($avg_online_secs) !!}</strong>/klien</span>
                    <span class="badge bg-warning-subtle text-dark border border-warning-subtle">{!! number_format($summary['total_users']) !!} Klien</span>
                </div>
                <div class="text-muted mt-1" style="font-size: 0.72rem;">
                    Total <strong>{!! number_format($summary['sessions']) !!}</strong> Sesi tercatat
                </div>
            </div>
        </div>
    </div>
</div>

@if ($mode === 'month')
<!-- GRAFIK TREN PEMAKAIAN HARIAN DALAM BULAN -->
<div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
    <div class="card-header bg-white border-light-subtle py-3 d-flex justify-content-between align-items-center">
        <h6 class="card-title mb-0 fw-bold text-dark">
            <i class="bi bi-graph-up text-primary me-2"></i>Tren Pemakaian Data Harian (Bulan: {{ $selected_month_label }})
        </h6>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Satuan: Gigabyte (GB)</span>
    </div>
    <div class="card-body p-3">
        <div style="position: relative; height: 260px; width: 100%;">
            <canvas id="chartDailyUsage"></canvas>
        </div>
    </div>
</div>
@endif

<!-- TABEL TOP PENGGUNA PEMAKAIAN DATA -->
<div class="card border-0 shadow-sm" style="border-radius: 12px;">
    <div class="card-header bg-white border-light-subtle py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h6 class="card-title mb-0 fw-bold text-dark">
                <i class="bi bi-people-fill text-primary me-2"></i>Peringkat Pengguna Terbanyak (Bulan: {{ $selected_month_label }})
            </h6>
            <span class="text-muted small">Menampilkan data teratas diurutkan berdasarkan Total Pemakaian (Download + Upload)</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <input type="text" id="filterUserTableInput" class="form-control form-control-sm" placeholder="Cari nama / username..." style="max-width: 220px;" onkeyup="filterUserTable()">
            <span class="badge bg-light text-dark border">{!! count($top_users) !!} Pengguna</span>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="userUsageTable">
            <thead class="table-light">
                <tr style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">
                    <th style="width: 45px;" class="text-center">#</th>
                    <th>Username / Pelanggan</th>
                    <th>Router &amp; IP</th>
                    <th class="text-center">Sesi</th>
                    <th>Waktu Online</th>
                    <th class="text-end">Download (Tx)</th>
                    <th class="text-end">Upload (Rx)</th>
                    <th class="text-end" style="min-width: 170px;">Total Pemakaian</th>
                    <th class="text-center" style="width: 50px;">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @if (empty($top_users))
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                        Tidak ada data pemakaian tercatat pada periode <strong>{{ $selected_month_label }}</strong>.
                    </td>
                </tr>
                @else
                @php foreach ($top_users as $idx => $u): 
                    $uTotal = (float)$u['total'];
                    $uDl = (float)$u['dl'];
                    $uUl = (float)$u['ul'];
                    $pct = min(100, max(2, round(($uTotal / $max_user_bytes) * 100))); @endphp
                <tr class="user-row">
                    <td class="text-center fw-bold text-muted" style="font-size: 0.85rem;">{!! $idx + 1 !!}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="usage-rank-badge">
                                {{ strtoupper(substr($u['username'], 0, 1)) }}
                            </div>
                            <div>
                                @if (!empty($u['customer_name']))
                                <div class="fw-bold text-dark user-search-field" style="font-size: 0.88rem;">
                                    {{ $u['customer_name'] }}
                                </div>
                                <div class="text-muted font-mono user-search-field" style="font-size: 0.75rem;">
                                    {{ $u['username'] }} 
                                    @if (!empty($u['customer_profile']))
                                    &bull; <span class="badge bg-light text-secondary border py-0" style="font-size:10px;">{{ $u['customer_profile'] }}</span>
                                    @endif
                                </div>
                                @else
                                <div class="fw-bold font-mono text-dark user-search-field" style="font-size: 0.88rem;">
                                    {{ $u['username'] }}
                                </div>
                                <div class="text-muted" style="font-size: 0.72rem;">
                                    <span class="badge bg-secondary-subtle text-secondary py-0" style="font-size:10px;">Hotspot / Voucher</span>
                                </div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark" style="font-size: 0.82rem;">{{ $u['router_name'] }}</div>
                        <div class="text-muted font-mono" style="font-size: 0.72rem;">{{ $u['nasipaddress'] }}</div>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2">{!! $u['sessions'] !!}</span>
                    </td>
                    <td style="font-size: 0.82rem;">
                        <i class="bi bi-clock text-muted me-1"></i>{!! format_durasi_clean((int)$u['total_secs']) !!}
                    </td>
                    <td class="text-end font-mono fw-semibold text-success" style="font-size: 0.85rem;">
                        ↓ {!! format_bytes($uDl) !!}
                    </td>
                    <td class="text-end font-mono fw-semibold text-primary" style="font-size: 0.85rem;">
                        ↑ {!! format_bytes($uUl) !!}
                    </td>
                    <td class="text-end">
                        <div class="fw-bold text-dark font-mono" style="font-size: 0.9rem;">{!! format_bytes($uTotal) !!}</div>
                        <div class="progress mt-1" style="height: 4px; background: #e2e8f0;" title="{!! $pct !!}% dari pemakaian tertinggi">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: {!! $pct !!}%;"></div>
                        </div>
                    </td>
                    <td class="text-center">
                        @can('manage-settings')
                        <form method="POST" action="{{ route('reports.usage.destroy', ['mode' => $mode, 'month' => $filter_month, 'from' => $filter_from, 'to' => $filter_to]) }}" class="d-inline" onsubmit="return confirm('Hapus seluruh riwayat pemakaian sesi untuk user \'{{ addslashes($u['username']) }}\' di periode ini?')">@csrf @method('DELETE')<input type="hidden" name="username" value="{{ $u['username'] }}"><input type="hidden" name="nasipaddress" value="{{ $u['nasipaddress'] }}"><button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Hapus Data Sesi User"><i class="bi bi-trash"></i></button></form>
                        @endcan
                    </td>
                </tr>
                @endforeach
                @endif
            </tbody>
        </table>
    </div>
</div>

@if ($mode === 'month')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('chartDailyUsage');
    if (!ctx) return;

    const labels = {!! json_encode($chart_labels) !!};
    const dataDl = {!! json_encode($chart_dl) !!};
    const dataUl = {!! json_encode($chart_ul) !!};

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Download (GB)',
                    data: dataDl,
                    backgroundColor: 'rgba(34, 197, 94, 0.75)',
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 1,
                    borderRadius: 4,
                },
                {
                    label: 'Upload (GB)',
                    data: dataUl,
                    backgroundColor: 'rgba(59, 130, 246, 0.75)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        font: { size: 12, weight: 'bold' }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y + ' GB';
                        },
                        footer: function(tooltipItems) {
                            let sum = 0;
                            tooltipItems.forEach(function(tooltipItem) {
                                sum += tooltipItem.parsed.y;
                            });
                            return 'Total: ' + sum.toFixed(2) + ' GB';
                        }
                    }
                }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    stacked: true,
                    beginAtZero: true,
                    ticks: {
                        callback: function(val) { return val + ' GB'; },
                        font: { size: 11 }
                    },
                    grid: { color: 'rgba(0, 0, 0, 0.05)' }
                }
            }
        }
    });
});
</script>
@endif

<script>
function filterUserTable() {
    const filter = document.getElementById('filterUserTableInput').value.toLowerCase();
    const rows = document.querySelectorAll('#userUsageTable tbody tr.user-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}
</script>



@endsection
