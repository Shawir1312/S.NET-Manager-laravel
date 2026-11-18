@extends('layouts.app')
@section('title', 'Pelanggan Rumahan')
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $bytes = function ($b) {
        $b = (float) $b;
        foreach (['B', 'KB', 'MB', 'GB', 'TB'] as $u) {
            if ($b < 1024 || $u === 'TB') {
                return round($b, $u === 'B' ? 0 : 2).' '.$u;
            }
            $b /= 1024;
        }
    };
    $isolirProfile = $settings['isolir_profile'] ?? 'isolir';
    $waPhone = fn ($p) => str_starts_with($n = preg_replace('/\D/', '', (string) $p), '0') ? '62'.substr($n, 1) : $n;
    $filtered = request()->filled('q') || request()->filled('status') || request('router_id') || request('month', now()->format('Y-m')) !== now()->format('Y-m');
@endphp
@push('head')
<style>
.online-dot { width:8px; height:8px; border-radius:50%; background:#22C55E; display:inline-block; box-shadow:0 0 0 3px rgba(34,197,94,.2); animation:dp 2s infinite; }
@keyframes dp { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
.rumahan-card { border-radius:10px; border:none; box-shadow:0 2px 6px rgba(0,0,0,0.06); transition:transform .15s; }
.rumahan-card:hover { transform: translateY(-2px); }
</style>
@endpush

@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-house-door text-primary me-2"></i>Pelanggan Rumahan</h1>
        <p class="page-subtitle">Pelanggan broadband rumahan aktif berbayar — telah ter-mapping ONT, PPPoE Secret, dan Paket Profil.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if ($selRouter && auth()->user()->can('manage-settings'))
            <form method="POST" action="{{ route('customers.sync') }}" class="d-inline" onsubmit="return confirm('Tarik dan sinkronkan seluruh PPPoE Secrets dari router {{ addslashes($selRouter->name) }} ke Database?')">
                @csrf <input type="hidden" name="router_id" value="{{ $selRouter->id }}">
                <button type="submit" class="btn btn-outline-info" title="Tarik & Sinkronkan Secrets dari MikroTik"><i class="bi bi-arrow-repeat me-1"></i> Sync MikroTik</button>
            </form>
        @endif
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayGlobal" @disabled($rows->isEmpty())><i class="bi bi-cash-stack me-1"></i> Bayar Kasir</button>
        <a href="{{ route('customers.create', array_filter(['router_id' => $selRouter?->id])) }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i> Tambah Pelanggan</a>
    </div>
</div>
@endsection

@section('content')
@include('partials.errors')

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-primary">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Total Rumahan</div>
                    <div class="fs-4 fw-bold text-primary">{{ number_format($stats['total'], 0, ',', '.') }} <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-primary-subtle text-primary p-3 rounded-circle"><i class="bi bi-house-door-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Potensi MRR: <strong>{{ $rp($stats['mrr']) }}</strong></div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-success">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Lunas ({{ $month->isSameMonth(now()) ? 'Bulan Ini' : $month->translatedFormat('F Y') }})</div>
                    <div class="fs-4 fw-bold text-success">{{ number_format($stats['lunas'], 0, ',', '.') }} <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-success-subtle text-success p-3 rounded-circle"><i class="bi bi-check-circle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Terkumpul: <strong>{{ $rp($stats['lunasNominal']) }}</strong></div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-danger">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Nunggak / Lewat JT</div>
                    <div class="fs-4 fw-bold text-danger">{{ number_format($stats['nunggak'], 0, ',', '.') }} <span class="fs-6 text-muted font-normal">Klien</span></div>
                </div>
                <div class="bg-danger-subtle text-danger p-3 rounded-circle"><i class="bi bi-exclamation-triangle-fill fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">Tunggakan: <strong>{{ $rp($stats['nunggakNominal']) }}</strong></div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-warning">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Status Koneksi</div>
                    <div class="fs-4 fw-bold text-warning">{{ $stats['online'] }} <span class="fs-6 text-muted font-normal">Online</span></div>
                </div>
                <div class="bg-warning-subtle text-warning p-3 rounded-circle"><i class="bi bi-broadcast fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">
                <span class="text-danger fw-semibold">{{ $stats['isolir'] }} Terisolir</span>
                @if ($stats['ontOnline'] > 0)<span class="text-info fw-semibold">| {{ $stats['ontOnline'] }} ONT</span>@endif
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-md-4 col-xl">
        <div class="card rumahan-card bg-white p-3 h-100 border-start border-4 border-info">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted small fw-bold text-uppercase">Pemakaian (Tx + Rx)</div>
                    <div class="fs-4 fw-bold text-info">{{ $bytes($stats['dl'] + $stats['ul']) }}</div>
                </div>
                <div class="bg-info-subtle text-info p-3 rounded-circle"><i class="bi bi-speedometer2 fs-4"></i></div>
            </div>
            <div class="small text-muted mt-2">
                <span title="Tx Byte (Download)"><i class="bi bi-arrow-down text-success"></i> Tx: {{ $bytes($stats['dl']) }}</span>
                <span class="mx-1">·</span>
                <span title="Rx Byte (Upload)"><i class="bi bi-arrow-up text-primary"></i> Rx: {{ $bytes($stats['ul']) }}</span>
            </div>
        </div>
    </div>
</div>

<div class="card table-card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
    <div class="table-toolbar p-3 bg-white border-bottom">
        <form method="GET" class="row g-2 align-items-center m-0 w-100">
            <div class="col-12 col-lg-auto d-flex align-items-center">
                <span class="fw-bold text-dark me-2" style="font-size: 13px;"><span class="badge bg-primary rounded-pill me-1">{{ $rows->count() }}</span> Pelanggan Rumahan</span>
            </div>
            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="router_id" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    <option value="0">🌐 Semua Cabang</option>
                    @foreach ($routers as $rt)<option value="{{ $rt->id }}" @selected($selRouter?->id === $rt->id)>📍 {{ $rt->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="status" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    @foreach (['active' => '🟢 Status Aktif', 'isolated' => '🔴 Status Isolir', 'online' => '📶 Sedang Online', 'offline' => '📴 Offline'] as $v => $l)
                        <option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-lg-auto flex-grow-1">
                <select name="month" class="form-select form-select-sm shadow-none w-100" style="border-radius: 8px;" onchange="this.form.submit()">
                    @foreach ($monthOptions as $v => $l)<option value="{{ $v }}" @selected($month->format('Y-m') === $v)>📅 {{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-lg-auto flex-grow-1">
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control shadow-none" style="border-radius: 8px 0 0 8px;" placeholder="Cari nama, user, ONT SN..." value="{{ request('q') }}">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                    @if ($filtered)<a href="{{ route('customers.index') }}" class="btn btn-outline-danger" title="Reset Filter" style="border-radius: 0 8px 8px 0;"><i class="bi bi-x-lg"></i></a>@endif
                </div>
            </div>
        </form>
    </div>

    {{-- Tampilan Desktop: Tabel Lengkap (Layar >= 992px) --}}
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle">
            <thead>
                <tr><th>Status</th><th>Username PPPoE</th><th>Nama & Kontak</th><th>Mapping ONT (Modem)</th><th>Paket Profil</th><th>Pemakaian (Tx + Rx)</th><th>Tagihan & JT</th><th>Sesi Online</th><th style="min-width: 140px;">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php $c = $r->c; @endphp
                <tr @if ($c->status === 'isolated') style="background-color: var(--red-pale);" @endif>
                    <td>
                        @if ($c->status === 'isolated')<span class="sts-isolated">🔴 Isolir</span>
                        @elseif ($r->session)<span class="sts-active" title="PPPoE Dial Online ({{ $r->session->framedipaddress }})">🟢 Online</span>
                        @elseif ($r->ontOnline)<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold px-2 py-1" title="Modem ONT Terhubung ke OLT/GenieACS">📶 ONT Online</span>
                        @elseif ($r->late)<span class="sts-suspended">⚠️ Jatuh Tempo</span>
                        @elseif ($c->status === 'active')<span class="badge bg-secondary-subtle text-secondary border fw-semibold px-2 py-1">● Offline</span>
                        @else<span class="sts-isolated">🔴 {{ ucfirst($c->status) }}</span>@endif
                    </td>
                    <td>
                        <a href="{{ route('customers.show', $c) }}" class="font-mono text-primary fw-bold text-decoration-none" style="font-size:13px;">{{ $c->pppoe_username }}</a>
                        <div class="text-muted" style="font-size:11px;"><i class="bi bi-router"></i> {{ $c->router?->name }}</div>
                        <div class="mt-1 d-flex align-items-center gap-1 flex-wrap">
                            <span class="badge bg-light text-secondary border font-mono" style="font-size:10px;" title="ID Login Portal Pelanggan"><i class="bi bi-person-lock text-primary me-1"></i>{{ $c->portal_username ?: $c->pppoe_username }}</span>
                            <button type="button" class="btn btn-outline-secondary py-0 px-1 btn-quick-portal" style="font-size: 10px; line-height: 1.3;" @include('customers._data', ['c' => $c]) title="Lihat & Atur Password Portal"><i class="bi bi-eye-fill text-primary"></i></button>
                        </div>
                    </td>
                    <td>
                        <div class="fw-bold text-dark">{{ $c->full_name }}</div>
                        @if ($c->phone)<a href="https://wa.me/{{ $waPhone($c->phone) }}" target="_blank" class="text-success text-decoration-none small" style="font-size:11px;"><i class="bi bi-whatsapp"></i> {{ $c->phone }}</a>@endif
                        @if ($c->address)<div class="text-muted text-truncate" style="max-width:200px; font-size:11px;" title="{{ $c->address }}"><i class="bi bi-geo-alt"></i> {{ $c->address }}</div>@endif
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-1 flex-wrap">
                            <span class="badge bg-light text-primary border font-mono fw-bold" style="font-size:12px;"><i class="bi bi-hdd-network me-1"></i>{{ $c->ont_sn ?: '-' }}</span>
                            @if ($r->ontOnline)<span class="badge bg-success-subtle text-success border border-success-subtle font-mono" style="font-size:10px;" title="Modem ONT Online di GenieACS"><span class="online-dot me-1"></span>ONT Online</span>
                            @elseif ($c->ont_sn)<span class="badge bg-secondary-subtle text-secondary border font-mono" style="font-size:10px;">ONT Offline</span>@endif
                            @if ($c->ont_sn)<a href="{{ route('ont-monitor.index', ['q' => $c->ont_sn]) }}" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Lihat di Monitor ONT" target="_blank"><i class="bi bi-box-arrow-up-right" style="font-size:10px;"></i></a>@endif
                        </div>
                        <div class="mt-1 d-flex align-items-center justify-content-between pt-1 border-top border-light">
                            <div class="text-truncate" style="max-width: 130px;" title="Nama Wi-Fi: {{ $c->ont_wifi_ssid ?: '-' }}"><i class="bi bi-wifi text-primary"></i> <span class="fw-bold text-dark" style="font-size:11px;">{{ $c->ont_wifi_ssid ?: 'Belum diatur' }}</span></div>
                            <button type="button" class="btn btn-outline-primary py-0 px-1 btn-quick-wifi" style="font-size: 10px; line-height: 1.3;" @include('customers._data', ['c' => $c]) title="Ganti Nama & Kode Wi-Fi (Push ONT)"><i class="bi bi-pencil-square"></i> Ubah</button>
                        </div>
                    </td>
                    <td>
                        @if ($c->status === 'isolated')
                            <span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-shield-slash me-1"></i>{{ $isolirProfile }}</span>
                            <div class="text-muted" style="font-size: 11px; margin-top: 3px;">Paket: {{ $c->profile ?: '-' }}</div>
                        @else
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold">{{ $c->profile ?: '-' }}</span>
                        @endif
                        <div class="fw-bold text-dark mt-1" style="font-size:12px;">{{ $rp($c->monthly_price) }} <span class="text-muted fw-normal" style="font-size:10px;">/bln</span></div>
                    </td>
                    <td>
                        @if ($r->usage && $r->usage['total'] > 0)
                            <span class="fw-bold font-mono text-dark" style="font-size:13px;" title="Total Pemakaian: Tx Byte + Rx Byte"><i class="bi bi-speedometer2 text-success me-1"></i>{{ $bytes($r->usage['total']) }}</span>
                            <div class="text-muted" style="font-size:11px;">
                                <span title="Tx Byte (Download)"><i class="bi bi-arrow-down text-success"></i> Tx: <strong>{{ $bytes($r->usage['dl']) }}</strong></span><span class="mx-1">·</span>
                                <span title="Rx Byte (Upload)"><i class="bi bi-arrow-up text-primary"></i> Rx: <strong>{{ $bytes($r->usage['ul']) }}</strong></span>
                            </div>
                            <div class="text-muted" style="font-size:10px;"><span class="text-secondary font-mono" style="font-size:10px;">(Tx + Rx)</span>
                                @if ($r->usage['secs'] > 0) &bull; <i class="bi bi-clock-history"></i> {{ intdiv($r->usage['secs'], 3600) ? intdiv($r->usage['secs'], 3600).'j ' : '' }}{{ intdiv($r->usage['secs'] % 3600, 60) }}m @endif
                            </div>
                        @else
                            <span class="badge bg-light text-muted border font-mono" style="font-size:11px;" title="Belum ada catatan pemakaian data pada periode ini">0 B</span>
                        @endif
                    </td>
                    <td>
                        <strong>Tgl {{ $c->due_day }}</strong><br>
                        @if ($c->is_free)<span class="badge bg-info-subtle text-info border border-info-subtle">Gratis</span>
                        @elseif ($r->paid > 0)<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                        @elseif ($r->pending > 0)<span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                        @elseif ($r->late)<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-exclamation-circle-fill me-1"></i>Nunggak</span>
                        @else<small class="text-muted">Belum bayar</small>@endif
                    </td>
                    <td>
                        @if ($r->session)
                            <div class="d-flex align-items-center gap-2"><span class="online-dot"></span><span class="font-mono text-success fw-bold" style="font-size:12px">{{ $r->session->framedipaddress }}</span></div>
                            @if ($r->session->acctstarttime)<div style="font-size:11px; color:var(--bs-success); margin-left:14px; margin-top:2px;">Up: {{ \Carbon\Carbon::parse($r->session->acctstarttime)->diffForHumans(null, true, true) }}</div>@endif
                        @elseif ($r->ontOnline)
                            <div class="d-flex align-items-center gap-1"><i class="bi bi-hdd-network text-info"></i><span class="text-info fw-semibold font-mono" style="font-size:11px">Modem Online</span></div>
                            <div class="text-muted" style="font-size:10px; margin-left:16px;">Inform: {{ \Carbon\Carbon::parse($r->ontOnline)->diffForHumans() }}</div>
                        @else
                            <span class="text-muted" style="font-size:12px">Offline</span>
                        @endif
                    </td>
                    <td>
                        <div class="d-flex gap-1 flex-nowrap">
                            @if ($c->phone)<button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-wa" @include('customers._data', ['c' => $c]) title="Kirim WhatsApp Tagihan / Info"><i class="bi bi-whatsapp"></i></button>@endif
                            <button type="button" class="btn btn-sm btn-outline-primary btn-icon btn-quick-wifi" @include('customers._data', ['c' => $c]) title="Ganti Nama & Kode Wi-Fi (Push ONT)"><i class="bi bi-wifi"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-dark btn-icon btn-quick-portal" @include('customers._data', ['c' => $c]) title="Lihat & Atur Password Portal Pelanggan"><i class="bi bi-person-lock"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-success btn-icon btn-quick-pay" @include('customers._data', ['c' => $c]) title="Catat Bayar Kasir"><i class="bi bi-cash-coin"></i></button>
                            @if (in_array($c->status, ['active', 'isolated'], true))
                                <form method="POST" action="{{ route('customers.status', $c) }}" class="d-inline" onsubmit="return confirm('Apakah Anda yakin ingin {{ $c->status === 'active' ? 'MENGISOLIR' : 'MEMBUKA ISOLIR' }} pelanggan {{ addslashes($c->full_name) }}?')">
                                    @csrf <input type="hidden" name="status" value="{{ $c->status === 'active' ? 'isolated' : 'active' }}">
                                    @if ($c->status === 'active')
                                        <button type="submit" class="btn btn-sm btn-outline-warning btn-icon" title="Isolir Sekarang"><i class="bi bi-slash-circle"></i></button>
                                    @else
                                        <button type="submit" class="btn btn-sm btn-outline-info btn-icon" title="Buka Isolir / Aktifkan Kembali"><i class="bi bi-play-circle-fill"></i></button>
                                    @endif
                                </form>
                            @endif
                            <a href="{{ route('customers.edit', $c) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Edit Data Pelanggan"><i class="bi bi-pencil"></i></a>
                            @can('manage-settings')
                                <form method="POST" action="{{ route('customers.destroy', $c) }}" class="d-inline" onsubmit="return confirm('Hapus pelanggan \'{{ addslashes($c->full_name) }}\' secara permanen dari Database dan MikroTik?')">
                                    @csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-house-x display-4 text-muted d-block mb-2"></i>Belum ada data pelanggan rumahan yang cocok dengan filter.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{-- Tampilan Mobile: Kartu Responsif (< 992px) --}}
    <div class="d-lg-none p-2 p-sm-3 bg-light">
        @if ($rows->isEmpty())
            <div class="text-center text-muted py-5 bg-white rounded-4 border"><i class="bi bi-house-x display-4 text-muted d-block mb-2"></i>Belum ada data pelanggan rumahan yang cocok dengan filter.</div>
        @else
        <div class="d-flex flex-column gap-3">
        @foreach ($rows as $r)
            @php
                $c = $r->c;
                $parts = explode(' ', trim($c->full_name));
                $initials = strtoupper(substr($parts[0] ?? 'P', 0, 1).substr($parts[1] ?? '', 0, 1)) ?: 'P';
                $avatarBg = ['#2563EB', '#7C3AED', '#059669', '#D97706', '#DB2777', '#0891B2', '#4F46E5'][$c->id % 7];
                $border = match (true) { $c->status === 'isolated' => 'border-danger', $r->late => 'border-warning', (bool) $r->session => 'border-success', (bool) $r->ontOnline => 'border-info', default => 'border-secondary' };
            @endphp
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white border-start border-4 {{ $border }}">
                <div class="p-3 pb-2 bg-white">
                    <div class="d-flex align-items-start justify-content-between gap-2">
                        <div class="d-flex align-items-center gap-2 min-w-0 flex-grow-1">
                            <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold flex-shrink-0 shadow-sm" style="width: 42px; height: 42px; font-size: 14px; background: {{ $avatarBg }};">{{ $initials }}</div>
                            <div class="min-w-0 flex-grow-1">
                                <h6 class="fw-bold text-dark mb-0 fs-6" style="word-break: break-word; line-height: 1.25;"><a href="{{ route('customers.show', $c) }}" class="text-dark text-decoration-none">{{ $c->full_name }}</a></h6>
                                <div class="d-flex align-items-center gap-1 mt-1 flex-wrap">
                                    <span class="badge bg-light text-primary border font-mono px-2 py-0" style="font-size: 11px;"><i class="bi bi-person me-1"></i>{{ $c->pppoe_username }}</span>
                                    <span class="text-muted" style="font-size: 11px;">({{ $c->router?->name }})</span>
                                </div>
                            </div>
                        </div>
                        <div class="text-end flex-shrink-0">
                            @if ($c->status === 'isolated')<span class="badge bg-danger text-white fw-semibold px-2 py-1" style="font-size: 11px;">🔴 Isolir</span>
                            @elseif ($r->session)<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold px-2 py-1" style="font-size: 11px;"><span class="online-dot me-1"></span>Online</span>
                            @elseif ($r->ontOnline)<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle fw-semibold px-2 py-1" style="font-size: 11px;">📶 ONT Online</span>
                            @elseif ($r->late)<span class="badge bg-warning-subtle text-warning border border-warning-subtle fw-semibold px-2 py-1" style="font-size: 11px;">⚠️ Jatuh Tempo</span>
                            @else<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle fw-semibold px-2 py-1" style="font-size: 11px;">● Offline</span>@endif
                        </div>
                    </div>
                </div>

                <div class="px-3 py-2 border-top border-bottom customer-billing-strip">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <span class="text-muted text-uppercase fw-semibold d-block" style="font-size: 10px; letter-spacing: 0.5px;">Biaya Langganan</span>
                            <span class="fw-bold text-dark fs-6 font-mono">{{ $rp($c->monthly_price) }}</span><span class="text-muted" style="font-size: 11px;">/bln</span>
                        </div>
                        <div class="text-end">
                            <span class="text-muted text-uppercase fw-semibold d-block" style="font-size: 10px; letter-spacing: 0.5px;">Jatuh Tempo: Tgl {{ $c->due_day }}</span>
                            @if ($r->paid > 0)<span class="badge bg-success text-white px-2 py-1" style="font-size: 11px;"><i class="bi bi-check-circle-fill me-1"></i>Lunas</span>
                            @elseif ($r->pending > 0)<span class="badge bg-warning text-dark px-2 py-1" style="font-size: 11px;"><i class="bi bi-clock-history me-1"></i>Pending</span>
                            @elseif ($r->late)<span class="badge bg-danger text-white px-2 py-1" style="font-size: 11px;"><i class="bi bi-exclamation-circle-fill me-1"></i>Nunggak</span>
                            @else<span class="badge bg-secondary text-white px-2 py-1" style="font-size: 11px;">Belum Bayar</span>@endif
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-white">
                    <div class="rounded-3 p-2 mb-2 customer-ont-box">
                        <div class="d-flex align-items-center justify-content-between pb-2 mb-2 border-bottom border-light-subtle flex-wrap gap-1">
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <i class="bi bi-hdd-network text-primary"></i><span class="fw-semibold text-secondary" style="font-size: 11px;">SN ONT:</span>
                                <span class="badge bg-white text-dark border font-mono fw-bold px-2 py-1" style="font-size: 11px;">{{ $c->ont_sn ?: '-' }}</span>
                                @if ($r->ontOnline)<span class="badge bg-success-subtle text-success border border-success-subtle font-mono px-2 py-1" style="font-size: 10px;"><span class="online-dot me-1"></span>ONT Online</span>
                                @elseif ($c->ont_sn)<span class="badge bg-secondary-subtle text-secondary border font-mono px-2 py-1" style="font-size: 10px;">ONT Offline</span>@endif
                            </div>
                            <button type="button" class="btn btn-sm btn-primary py-1 px-2 btn-quick-wifi fw-semibold" style="font-size: 11px; border-radius: 6px;" @include('customers._data', ['c' => $c])><i class="bi bi-wifi me-1"></i>Ganti Wi-Fi</button>
                        </div>
                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                            <div class="min-w-0" style="flex: 1 1 150px;">
                                <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Nama Wi-Fi (SSID):</span>
                                <div class="fw-bold text-dark" style="font-size: 13px; word-break: break-word;"><i class="bi bi-broadcast text-success me-1"></i>{{ $c->ont_wifi_ssid ?: 'Belum diatur' }}</div>
                            </div>
                            @if ($c->ont_wifi_pass)
                                <div class="text-end" style="flex: 0 0 auto;">
                                    <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Pass Wi-Fi:</span>
                                    <span class="badge bg-white text-dark border font-mono px-2 py-1" style="font-size: 11px;"><i class="bi bi-key-fill text-muted me-1"></i>{{ $c->ont_wifi_pass }}</span>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between rounded-3 p-2 mb-2 customer-portal-box">
                        <div class="d-flex align-items-center gap-1 flex-wrap">
                            <i class="bi bi-shield-lock text-warning-emphasis"></i><span class="text-secondary fw-semibold" style="font-size: 11px;">Portal:</span>
                            <span class="badge bg-white text-dark border font-mono px-2 py-1" style="font-size: 11px;">{{ $c->portal_username ?: $c->pppoe_username }}</span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-warning text-dark py-0 px-2 btn-quick-portal fw-semibold" style="font-size: 11px; border-radius: 6px;" @include('customers._data', ['c' => $c])><i class="bi bi-pencil-square me-1"></i>Atur</button>
                    </div>

                    <div class="rounded-3 p-2 mb-2 customer-usage-box">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;"><i class="bi bi-speedometer2 text-success me-1"></i>Pemakaian Data (Tx + Rx Byte)</span>
                                <span class="fw-bold text-success font-mono fs-6">{{ $bytes($r->usage['total'] ?? 0) }}</span>
                            </div>
                            <div class="text-end" style="font-size: 11px;">
                                <div class="text-secondary"><i class="bi bi-arrow-down text-success"></i> Tx: <span class="fw-bold font-mono text-dark">{{ $bytes($r->usage['dl'] ?? 0) }}</span></div>
                                <div class="text-secondary"><i class="bi bi-arrow-up text-primary"></i> Rx: <span class="fw-bold font-mono text-dark">{{ $bytes($r->usage['ul'] ?? 0) }}</span></div>
                            </div>
                        </div>
                        <div class="pt-1 mt-1 border-top border-success-subtle d-flex align-items-center justify-content-between usage-summary-footer" style="font-size: 10px;">
                            <span><i class="bi bi-calculator me-1"></i>Total = Tx Byte + Rx Byte</span>
                            @if (($r->usage['secs'] ?? 0) > 0)<span><i class="bi bi-clock-history me-1"></i>{{ intdiv($r->usage['secs'], 3600) }}j {{ intdiv($r->usage['secs'] % 3600, 60) }}m ({{ $r->usage['sessions'] }} Sesi)</span>@endif
                        </div>
                    </div>

                    <div class="row g-2 mb-2" style="font-size: 12px;">
                        <div class="col-6">
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">WhatsApp:</span>
                            @if ($c->phone)<a href="https://wa.me/{{ $waPhone($c->phone) }}" target="_blank" class="text-success text-decoration-none fw-semibold d-inline-flex align-items-center gap-1"><i class="bi bi-whatsapp"></i> {{ $c->phone }}</a>@else<span class="text-muted">-</span>@endif
                        </div>
                        <div class="col-6 text-end">
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Paket Profil:</span>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-mono">{{ $c->profile ?: '-' }}</span>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Sesi IP:</span>
                            @if ($r->session)<span class="text-success fw-semibold font-mono" style="font-size: 11px;">{{ $r->session->framedipaddress }}</span>
                            @elseif ($r->ontOnline)<span class="text-info fw-semibold font-mono" style="font-size: 11px;"><i class="bi bi-hdd-network me-1"></i>Modem Online (ONT)</span>
                            @else<span class="text-muted" style="font-size: 11px;">Offline</span>@endif
                        </div>
                        @if ($c->address)
                            <div class="col-6 text-end">
                                <span class="text-muted d-block" style="font-size: 10px; font-weight: 600; text-transform: uppercase;">Alamat / Lokasi:</span>
                                <span class="text-dark text-truncate d-inline-block" style="max-width: 150px;" title="{{ $c->address }}"><i class="bi bi-geo-alt text-danger me-1"></i>{{ $c->address }}</span>
                            </div>
                        @endif
                    </div>

                    <div class="pt-2 border-top">
                        <div class="d-grid gap-2 mb-2" style="grid-template-columns: 1fr 1fr 1fr;">
                            @if ($c->phone)
                                <button type="button" class="btn btn-sm btn-outline-success fw-semibold btn-quick-wa d-flex align-items-center justify-content-center gap-1 py-2" @include('customers._data', ['c' => $c])><i class="bi bi-whatsapp"></i> <span>WA</span></button>
                            @else
                                <button type="button" class="btn btn-sm btn-light text-muted border d-flex align-items-center justify-content-center gap-1 py-2" disabled><i class="bi bi-whatsapp"></i> <span>No WA</span></button>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-primary fw-semibold btn-quick-pay d-flex align-items-center justify-content-center gap-1 py-2" @include('customers._data', ['c' => $c])><i class="bi bi-cash-coin"></i> <span>Bayar</span></button>
                            <button type="button" class="btn btn-sm btn-outline-dark fw-semibold btn-quick-portal d-flex align-items-center justify-content-center gap-1 py-2" @include('customers._data', ['c' => $c])><i class="bi bi-person-lock"></i> <span>Portal</span></button>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            @if (in_array($c->status, ['active', 'isolated'], true))
                                <form method="POST" action="{{ route('customers.status', $c) }}" class="flex-grow-1 m-0" onsubmit="return confirm('{{ $c->status === 'active' ? 'Isolir' : 'Buka isolir' }} pelanggan {{ addslashes($c->full_name) }}?')">
                                    @csrf <input type="hidden" name="status" value="{{ $c->status === 'active' ? 'isolated' : 'active' }}">
                                    @if ($c->status === 'active')
                                        <button type="submit" class="btn btn-sm btn-outline-warning text-dark w-100 fw-semibold d-flex align-items-center justify-content-center gap-1 py-2"><i class="bi bi-slash-circle text-danger"></i> <span>Isolir</span></button>
                                    @else
                                        <button type="submit" class="btn btn-sm btn-outline-success w-100 fw-semibold d-flex align-items-center justify-content-center gap-1 py-2"><i class="bi bi-play-circle-fill"></i> <span>Buka Isolir</span></button>
                                    @endif
                                </form>
                            @endif
                            <a href="{{ route('customers.edit', $c) }}" class="btn btn-sm btn-light border text-primary fw-semibold px-3 py-2 d-flex align-items-center gap-1"><i class="bi bi-pencil"></i> <span>Edit</span></a>
                            @can('manage-settings')
                                <form method="POST" action="{{ route('customers.destroy', $c) }}" class="m-0" onsubmit="return confirm('Hapus pelanggan \'{{ addslashes($c->full_name) }}\' secara permanen?')">
                                    @csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-light border text-danger fw-semibold px-3 py-2 d-flex align-items-center gap-1"><i class="bi bi-trash"></i> <span>Hapus</span></button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
        </div>
        @endif
    </div>
</div>

@include('customers._modals', ['customerOptions' => $rows->pluck('c')])
@endsection
