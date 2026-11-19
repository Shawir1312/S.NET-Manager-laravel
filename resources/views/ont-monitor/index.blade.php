@extends('layouts.app')
@section('title', 'Monitor ONT (GenieACS)')
@section('page-header')
@endsection
@section('content')
@include('partials.errors')
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <label class="form-label small fw-bold text-muted mb-1 text-uppercase" style="letter-spacing: .5px;"><i class="bi bi-search me-1 text-primary"></i> Cari Serial / SSID / Tag</label>
        <div class="input-group">
            <input type="text" id="ontSearchInput" class="form-control form-control-lg font-monospace" placeholder="Ketik Serial Number, Nama Pelanggan, SSID, atau IP ONT..." autofocus>
            <button class="btn btn-primary px-4 fw-bold" type="button" id="ontSearchBtn"><i class="bi bi-search me-1"></i> Cari</button>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <h5 class="fw-bold mb-0 text-dark d-flex align-items-center"><i class="bi bi-router-fill text-primary me-2"></i>Server: <span class="text-primary ms-1">{{ $selServer->name ?? 'SERVER ACS' }}</span></h5>
        <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-2 py-1 fs-7">{{ $devices->count() }} ONT</span>
        <small class="text-muted" id="syncInfo"><i class="bi bi-clock-history me-1"></i>{{ $lastSync ? 'Sinkron '.\Illuminate\Support\Carbon::createFromTimestamp($lastSync)->diffForHumans() : 'Belum pernah sinkron' }}</small>
    </div>
    <div class="d-flex align-items-center gap-2">
        @if ($servers->count() > 1)
        <form method="GET" class="m-0">
            <select name="server_id" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach ($servers as $srv)<option value="{{ $srv->id }}" @selected($selServer?->id === $srv->id)>{{ $srv->name }}</option>@endforeach
            </select>
        </form>
        @endif
        @can('configure-ont')
        <form method="POST" action="{{ route('ont-monitor.sync') }}" class="m-0">@csrf<button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i> Sinkron dari ACS</button></form>
        @endcan
        @can('manage-settings')<a href="{{ route('settings.genieacs.edit') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-gear me-1"></i> Config ACS</a>@endcan
    </div>
</div>

@if ($servers->isEmpty())
    <div class="alert alert-warning py-3">
        <h5 class="alert-heading"><i class="bi bi-exclamation-triangle me-2"></i>Belum Ada Server GenieACS!</h5>
        <p class="mb-0">Anda perlu mengkonfigurasi server GenieACS terlebih dahulu di menu <strong>Administrasi &gt; Config GenieACS</strong>.</p>
    </div>
@else
    <div id="activeRemotesBanner" class="alert alert-warning border-warning justify-content-between align-items-center flex-wrap gap-2 mb-4 {{ $remotes->isEmpty() ? 'd-none' : 'd-flex' }}">
        <div class="d-flex align-items-center gap-2">
            <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
            <div>
                <strong>Akses Remote ONT Sedang Aktif:</strong>
                <span id="activeRemotesText" class="ms-1 small">
                    @foreach ($remotes as $sn => $ar)
                    <span class="badge bg-dark me-1">SN: {{ $sn }} &rarr; <a href="{{ $remoteService->url($ar) }}" target="_blank" rel="noopener" class="text-warning text-decoration-none font-monospace">{{ $remoteService->url($ar) }}</a> (Sisa {{ (int) floor(now()->diffInSeconds($ar->expires_at) / 60) }}m)</span>
                    @endforeach
                </span>
            </div>
        </div>
        @can('configure-ont')
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-xs btn-outline-danger py-1 px-2" style="font-size: .75rem;" onclick="closeAllRemotes()"><i class="bi bi-x-circle me-1"></i> Tutup Semua Sesi Remote</button>
            <small class="text-muted fst-italic d-none d-md-inline">Akses remote akan otomatis terhapus dalam 15 menit.</small>
        </div>
        @endcan
    </div>

    <div class="row g-3" id="ontGridContainer">
    @forelse ($devices as $d)
        @php
            $snap = (array) ($d->parameters_snapshot ?? []);
            $ssids = array_values(array_unique(array_filter([$snap['ssid_24'] ?? null, $snap['ssid_5g'] ?? null])));
            $rx = $snap['rx'] ?? null;
            $isOnline = $d->last_inform_at && $d->last_inform_at->gte($threshold);
            $displayName = $d->customer_name ?: ($ssids[0] ?? $d->serial_number);
            $subTitle = $d->customer_name ? ($ssids ? 'SSID: '.$ssids[0] : $d->customer_username) : ($d->product_class ?: 'ONT Device');
            $mins = $d->last_inform_at ? $d->last_inform_at->diffInMinutes(now()) : null;
            $lastSeen = $mins === null ? '-' : ($mins < 1 ? 'Baru saja' : ($mins < 60 ? round($mins).'m lalu' : ($mins < 1440 ? round($mins / 60).'j lalu' : $d->last_inform_at->format('d/m/Y'))));
            $rxClass = $rx !== null && (float) $rx < -27 ? 'text-danger fw-bold' : ($rx !== null && (float) $rx < -25 ? 'text-warning fw-bold' : 'text-success');
            $remote = $remotes[$d->serial_number] ?? null;
            $remSec = $remote ? max(0, (int) now()->diffInSeconds($remote->expires_at, false)) : 0;
        @endphp
        <div class="col-12 col-md-6 col-lg-4 ont-card-wrapper" data-search="{{ strtolower($d->serial_number.' '.$displayName.' '.$subTitle.' '.$d->ip_address.' '.implode(' ', $ssids)) }}">
            <div class="card border-0 shadow-sm h-100 rounded-3" style="transition: transform .15s ease, box-shadow .15s ease;">
                <div class="card-body p-3 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: {{ $isOnline ? '#e8f5e9' : '#fff3e0' }}; border: 2px solid {{ $isOnline ? '#4caf50' : '#ff9800' }};">
                                    <div style="width: 14px; height: 14px; border-radius: 50%; background: {{ $isOnline ? '#4caf50' : '#ff9800' }};"></div>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0 text-dark text-truncate" style="max-width: 170px;" title="{{ $displayName }}">{{ $displayName }}</h6>
                                    <small class="text-muted text-truncate d-block" style="font-size: .75rem; max-width: 170px;" title="{{ $subTitle }}">{{ $subTitle }}</small>
                                </div>
                            </div>
                            <div>
                                @if ($isOnline)
                                <span class="text-success small fw-bold d-flex align-items-center gap-1" style="font-size: .75rem;"><span style="width:7px; height:7px; border-radius:50%; background:#28a745; display:inline-block;"></span> Online</span>
                                @else
                                <span class="text-secondary small d-flex align-items-center gap-1" style="font-size: .75rem;"><span style="width:7px; height:7px; border-radius:50%; background:#adb5bd; display:inline-block;"></span> Offline</span>
                                @endif
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-1 mb-2 pt-1 border-top">
                            <span class="small text-muted text-truncate" style="max-width: 140px; font-size: .75rem;" title="{{ $d->manufacturer }} {{ $d->product_class }}"><i class="bi bi-hdd-network me-1"></i>{{ $d->vendor?->label() }} {{ $d->product_class }}</span>
                            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 font-monospace px-2 py-1" style="font-size: .72rem;">{{ $d->serial_number }}</span>
                            <span class="small text-muted" style="font-size: .72rem;"><i class="bi bi-clock me-1"></i>{{ $lastSeen }}</span>
                        </div>
                        @if ($ssids)
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach ($ssids as $sName)<span class="badge bg-light text-dark border px-2 py-1" style="font-size: .7rem; font-weight: 500;"><i class="bi bi-wifi me-1 text-primary"></i>{{ $sName }}</span>@endforeach
                        </div>
                        @endif
                        <div class="d-flex justify-content-between align-items-center small text-muted mb-3 bg-light p-2 rounded font-monospace" style="font-size: .75rem;">
                            <div><i class="bi bi-globe me-1"></i>IP: <span class="fw-bold text-dark">{{ $d->ip_address ?: '-' }}</span></div>
                            <div>RX: <span class="{{ $rxClass }}">{{ $rx ?? '-' }} dBm</span></div>
                        </div>
                    </div>
                    <div class="d-flex gap-2 pt-2 border-top">
                        <a href="{{ route('devices.show', $d) }}" class="btn btn-outline-primary btn-sm flex-fill fw-semibold text-decoration-none text-center" style="font-size: .78rem;"><i class="bi bi-search me-1"></i> Detail &amp; Config</a>
                        @can('configure-ont')
                            @if ($remote)
                            <button type="button" class="btn btn-success btn-sm flex-fill fw-bold font-monospace remote-active-btn" style="font-size: .78rem;" data-rem="{{ $remSec }}" onclick="handleActiveRemoteClick(@js($remoteService->url($remote)), @js(route('ont-monitor.remote.close', $remote)))">
                                <span class="spinner-grow spinner-grow-sm me-1" style="width: 8px; height: 8px;"></span> <span class="rem-btn-timer">{{ sprintf('%02d:%02d', intdiv($remSec, 60), $remSec % 60) }}</span>
                            </button>
                            @else
                            <button type="button" class="btn btn-warning text-white btn-sm flex-fill fw-bold" style="background:#f05023; border-color:#f05023; font-size: .78rem;" onclick="startOntRemote(this, @js(route('ont-monitor.remote', $d)))"><i class="bi bi-plug-fill me-1"></i> Remote</button>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    @empty
        <div class="col-12 text-center text-muted py-5">
            <i class="bi bi-modem text-secondary" style="font-size: 3rem;"></i>
            <h6 class="mt-3">Tidak ada perangkat ONT yang terdeteksi pada server GenieACS ini.</h6>
            @can('configure-ont')<p class="small">Klik <strong>Sinkron dari ACS</strong> untuk mengambil daftar ONT.</p>@endcan
        </div>
    @endforelse
    </div>
@endif

<script>
const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
const jsonReq = (url, method) => fetch(url, {method, headers: {'X-CSRF-TOKEN': CSRF_TOKEN, 'Accept': 'application/json'}}).then(r => r.json());
const fmt = rem => String(Math.floor(rem / 60)).padStart(2, '0') + ':' + String(rem % 60).padStart(2, '0');

const searchBox = document.getElementById('ontSearchInput');
function applySearch() {
    const q = (searchBox?.value || '').toLowerCase().trim();
    try { sessionStorage.setItem('ontSearch', q); } catch (e) {}
    document.querySelectorAll('.ont-card-wrapper').forEach(card => card.style.display = card.dataset.search.includes(q) ? '' : 'none');
}
searchBox?.addEventListener('input', applySearch);
try { if (searchBox && sessionStorage.getItem('ontSearch')) { searchBox.value = sessionStorage.getItem('ontSearch'); applySearch(); } } catch (e) {}

@if ($selServer)
@can('configure-ont')
// Data real-time: sinkron ke GenieACS saat halaman dibuka (bila >60 dtk sejak sinkron terakhir) lalu tiap 2 menit.
(function () {
    const SYNC_URL = @json(route('ont-monitor.sync', ['server_id' => $selServer->id]));
    const lastSync = @json($lastSync);
    const info = document.getElementById('syncInfo');
    let busy = false;
    async function syncNow() {
        if (busy || document.hidden || document.querySelector('.modal.show')) return;
        busy = true;
        info.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sinkron dari ACS…';
        try {
            const res = await jsonReq(SYNC_URL, 'POST');
            if (res.success) { location.reload(); return; }
            info.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(res.message || 'Sinkron gagal') + '</span>';
        } catch (e) {
            info.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>Sinkron gagal: ' + escHtml(e.message) + '</span>';
        }
        busy = false;
    }
    if (!lastSync || Date.now() / 1000 - lastSync > 60) syncNow();
    setInterval(syncNow, 120000);
})();
@endcan
@endif

function runCountdown(btn, rem) {
    const span = btn.querySelector('.rem-btn-timer');
    const itv = setInterval(() => {
        rem--;
        if (rem <= 0) { clearInterval(itv); btn.className = 'btn btn-secondary btn-sm flex-fill'; btn.innerHTML = 'Kadaluarsa'; btn.disabled = true; }
        else if (span) span.innerText = fmt(rem);
    }, 1000);
}
document.querySelectorAll('.remote-active-btn').forEach(btn => runCountdown(btn, parseInt(btn.dataset.rem || '0')));

async function startOntRemote(btn, url) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        const res = await jsonReq(url, 'POST');
        if (!res.success) throw new Error(res.error);
        window.open(res.remote_url, '_blank');
        btn.disabled = false;
        btn.className = 'btn btn-success btn-sm flex-fill fw-bold font-monospace remote-active-btn';
        btn.style.background = btn.style.borderColor = '#28a745';
        btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-1" style="width: 8px; height: 8px;"></span> <span class="rem-btn-timer">' + fmt(res.remaining_seconds) + '</span>';
        btn.onclick = () => handleActiveRemoteClick(res.remote_url, res.close_url);
        runCountdown(btn, res.remaining_seconds);
        document.getElementById('activeRemotesBanner')?.classList.replace('d-none', 'd-flex');
    } catch (e) {
        alert('Gagal membuka akses remote: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug-fill me-1"></i> Remote';
    }
}
function handleActiveRemoteClick(url, closeUrl) {
    if (confirm("Sesi Remote ONT sedang AKTIF!\n\n• Klik OK untuk MEMBUKA ULANG Web GUI ONT di Tab Baru.\n• Klik BATAL/CANCEL jika ingin MENUTUP akses remote sekarang.")) {
        window.open(url, '_blank');
    } else if (confirm('Tutup dan hapus akses remote port forward ONT ini sekarang?')) {
        jsonReq(closeUrl, 'DELETE').then(() => location.reload()).catch(e => alert('Gagal menutup remote: ' + e.message));
    }
}
function closeAllRemotes() {
    if (!confirm('Tutup dan bersihkan SEMUA sesi akses remote ONT yang sedang aktif sekarang?')) return;
    jsonReq(@json(route('ont-monitor.remote.close-all')), 'DELETE').then(res => { alert(res.message); location.reload(); }).catch(e => alert('Gagal menutup semua remote: ' + e.message));
}
</script>
@endsection
