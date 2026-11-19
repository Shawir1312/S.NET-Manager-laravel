@extends('layouts.app')
@section('title', 'Setting ONT Hotspot')
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-qr-code-scan me-2 text-primary"></i>Setting ONT Hotspot</h1>
        <p class="page-subtitle">Scan / isi SN ONT → cek online → isi nama pemilik (komentar ONT di GenieACS) → semua pengaturan ikut template hotspot</p>
    </div>
    @can('manage-templates')
    <a href="{{ route('templates.index') }}" class="btn btn-outline-secondary"><i class="bi bi-file-earmark-code me-1"></i> Template ONT</a>
    @endcan
</div>
@endsection
@section('content')
@if ($templates->isEmpty())
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i>Belum ada <strong>template Hotspot</strong>. Buat dulu di menu <strong>Template ONT</strong> (jenis layanan: Hotspot/Bridge).</div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-7">
        {{-- LANGKAH 1: SN --}}
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="card-title"><span class="badge bg-primary rounded-circle me-2">1</span>Serial Number ONT</h5></div>
            <div class="card-body">
                @if ($servers->count() > 1)
                <div class="mb-3">
                    <label class="form-label">Server GenieACS</label>
                    <select class="form-select" id="serverSelect">
                        @foreach ($servers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </div>
                @endif
                <label class="form-label">SN ONT</label>
                <div class="input-group input-group-lg mb-2">
                    <input type="text" class="form-control font-mono text-uppercase" id="snInput" placeholder="Contoh: ZTEGC1234567" autocomplete="off" autocapitalize="characters" maxlength="64">
                    <button class="btn btn-primary" type="button" id="btnSearch"><i class="bi bi-search me-1"></i>Cek</button>
                </div>
                <button class="btn btn-outline-dark w-100" type="button" id="btnScan"><i class="bi bi-camera me-1"></i> Scan QR / Barcode SN pakai Kamera</button>
                <div id="scanBox" class="mt-3" style="display:none;">
                    <div id="qrReader" class="rounded overflow-hidden border" style="width:100%; max-width:420px; margin:0 auto;"></div>
                    <div class="text-center mt-2"><button class="btn btn-sm btn-outline-danger" type="button" id="btnStopScan"><i class="bi bi-x-circle me-1"></i>Tutup Kamera</button></div>
                    <div class="form-text text-center">Arahkan kamera ke QR code atau barcode SN di label ONT. Kamera butuh HTTPS &amp; izin browser.</div>
                </div>
                <div id="scanRaw" class="small text-muted mt-2 text-break"></div>
                <div id="searchResult" class="mt-3"></div>
            </div>
        </div>

        {{-- LANGKAH 2: NAMA & TERAPKAN --}}
        <div class="card shadow-sm mb-4" id="stepApply" style="display:none;">
            <div class="card-header"><h5 class="card-title"><span class="badge bg-success rounded-circle me-2">2</span>Nama &amp; Terapkan Template</h5></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label">Nama Pemilik / Komentar ONT <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="nameInput" maxlength="32" placeholder="Contoh: Warung Bu Ani">
                        <div class="form-text">Disimpan sebagai tag/komentar ONT di GenieACS untuk menandai ONT ini milik siapa; tag lama di ONT diganti dengan nama ini (juga tampil di Monitor ONT).</div>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Template Hotspot</label>
                        <select class="form-select" id="templateSelect">
                            @foreach ($templates as $t)<option value="{{ $t['id'] }}">{{ $t['name'] }}{{ $t['is_default'] ? ' (default)' : '' }}</option>@endforeach
                        </select>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded border bg-light small" id="templatePreview"></div>
                <button class="btn btn-success btn-lg w-100 mt-3" type="button" id="btnApply"><i class="bi bi-send-check me-1"></i> Terapkan ke ONT</button>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        {{-- STATUS ONT & PROGRES --}}
        <div class="card shadow-sm mb-4" id="deviceCard" style="display:none;">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-router me-1"></i>Status ONT</h5></div>
            <div class="card-body" id="deviceBody"></div>
        </div>
        <div class="card shadow-sm" id="progressCard" style="display:none;">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-activity me-1"></i>Progres Konfigurasi</h5></div>
            <div class="card-body" id="progressBody"></div>
        </div>
        <div class="card border-0 bg-light">
            <div class="card-body small text-muted">
                <h6 class="fw-bold text-dark"><i class="bi bi-info-circle me-1"></i>Cara pakai</h6>
                <ol class="ps-3 mb-0">
                    <li>Scan QR/barcode SN di label ONT, atau ketik SN lalu klik <strong>Cek</strong>.</li>
                    <li>Bila ONT <strong>online</strong>, isi nama pemilik ONT lalu klik <strong>Terapkan</strong>. Nama disimpan sebagai komentar (tag) ONT di GenieACS.</li>
                    <li>VLAN, binding LAN/SSID, nama SSID &amp; password WiFi otomatis mengikuti template hotspot.</li>
                </ol>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(() => {
    const TEMPLATES = @json($templates);
    const URL_SEARCH = @json(route('api.ont.search'));
    const URL_ONLINE = @json(url('/api/ont/__ID__/check-online'));
    const URL_APPLY = @json(route('ont-hotspot.apply'));
    const $ = id => document.getElementById(id);
    let device = null, scanner = null;

    const alertBox = (tone, html) => `<div class="alert alert-${tone} py-2 mb-0">${html}</div>`;

    // GPON SN hex 16 karakter (mis. label Huawei 4857544379FC699D) → bentuk PON di ACS (HWTC79FC699D).
    function hexToPon(h) {
        if (!/^[0-9A-F]{16}$/.test(h)) return null;
        const vendor = h.slice(0, 8).match(/../g).map(b => String.fromCharCode(parseInt(b, 16))).join('');
        return /^[A-Z]{4}$/.test(vendor) ? vendor + h.slice(8) : null;
    }

    // Kandidat SN dari isi QR/barcode (bisa berisi SN perangkat, MAC, dll.), urut dari yang paling mungkin GPON SN.
    function snCandidates(text) {
        const t = String(text).toUpperCase();
        const out = [];
        for (let i = 0; i + 16 <= t.length; i++) { const p = hexToPon(t.slice(i, i + 16)); if (p) out.push(p); }
        (t.match(/[A-Z]{4}[0-9A-F]{8}/g) || []).forEach(x => out.push(x));
        const labeled = (t.match(/S\/?N\s*[:=]?\s*([A-Z0-9]{8,24})/) || [])[1];
        if (labeled) out.push(hexToPon(labeled) || labeled);
        (t.match(/[A-Z0-9]{8,24}/g) || []).sort((a, b) => b.length - a.length).forEach(x => out.push(hexToPon(x) || x));
        return [...new Set(out)].slice(0, 5);
    }

    async function startScan() {
        if (!window.Html5Qrcode) { OntApp.toast('Library scanner gagal dimuat. Periksa koneksi internet.', 'danger'); return; }
        $('scanBox').style.display = '';
        scanner = new Html5Qrcode('qrReader');
        try {
            await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 160 } }, decoded => {
                const candidates = snCandidates(decoded);
                $('snInput').value = candidates[0] || String(decoded).trim();
                $('scanRaw').textContent = 'Terbaca dari kamera: ' + String(decoded).slice(0, 120);
                stopScan();
                search(candidates);
            });
        } catch (e) {
            $('scanBox').style.display = 'none';
            OntApp.toast('Kamera tidak bisa dibuka: ' + (e?.message || e) + '. Pastikan situs memakai HTTPS & izin kamera diberikan.', 'danger');
        }
    }
    async function stopScan() {
        try { if (scanner?.isScanning) await scanner.stop(); scanner?.clear(); } catch (e) {}
        $('scanBox').style.display = 'none';
    }

    function renderDevice(d, ready) {
        $('deviceCard').style.display = '';
        $('deviceBody').innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div><div class="fw-bold font-mono">${escHtml(d.serial_number)}</div>
                <div class="small text-muted">${escHtml(d.manufacturer || '')} ${escHtml(d.product_class || '')}</div></div>
                <span class="badge bg-${escHtml(ready.badge || 'secondary')}">${escHtml(ready.label || '')}</span>
            </div>
            <div class="small">${escHtml(ready.message || '')}</div>
            ${ready.online ? '' : '<button class="btn btn-sm btn-outline-primary mt-2" id="btnRecheck"><i class="bi bi-arrow-repeat me-1"></i>Cek Online Lagi</button>'}`;
        $('btnRecheck')?.addEventListener('click', () => checkOnline(d));
    }

    async function checkOnline(d) {
        device = null;
        $('stepApply').style.display = 'none';
        $('searchResult').innerHTML = alertBox('info', '<span class="spinner-border spinner-border-sm me-2"></span>Mengecek status online ONT…');
        try {
            const r = await OntApp.api(URL_ONLINE.replace('__ID__', d.id), { method: 'POST' });
            renderDevice(d, r);
            if (r.online && r.status !== 'unsupported') {
                device = d;
                $('searchResult').innerHTML = alertBox('success', '<i class="bi bi-check-circle me-1"></i>ONT online. Lanjutkan isi nama pemilik ONT.');
                $('stepApply').style.display = '';
                $('nameInput').focus();
                renderPreview();
            } else {
                $('searchResult').innerHTML = alertBox('warning', '<i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(r.message));
            }
        } catch (e) {
            $('searchResult').innerHTML = alertBox('danger', escHtml(e.message));
        }
    }

    // candidates: kandidat SN dari scan; ketik manual = satu kandidat (hex Huawei diubah ke bentuk PON).
    async function search(candidates = null) {
        const typed = $('snInput').value.trim().toUpperCase().replace(/\s+/g, '');
        const list = Array.isArray(candidates) && candidates.length ? candidates : [hexToPon(typed) || typed];
        if (list[0].length < 6) { OntApp.toast('Isi SN ONT terlebih dahulu.', 'warning'); return; }
        if (!Array.isArray(candidates)) $('scanRaw').textContent = '';
        $('progressCard').style.display = 'none';
        try {
            let r = null;
            for (const sn of list) {
                $('snInput').value = sn;
                $('searchResult').innerHTML = alertBox('info', '<span class="spinner-border spinner-border-sm me-2"></span>Mencari ONT ' + escHtml(sn) + ' di GenieACS…');
                const body = { serial_number: sn };
                if ($('serverSelect')) body.genie_server_id = Number($('serverSelect').value);
                r = await OntApp.api(URL_SEARCH, { method: 'POST', body });
                if (r.devices && r.devices.length) break;
            }
            if (!r.devices || r.devices.length === 0) {
                $('deviceCard').style.display = 'none'; $('stepApply').style.display = 'none';
                const tried = list.length > 1 ? '<div class="small mt-1">Dicoba: ' + list.map(escHtml).join(', ') + '. Pastikan yang di-scan barcode <strong>GPON SN / PON SN</strong>, bukan SN perangkat.</div>' : '';
                $('searchResult').innerHTML = alertBox('warning', escHtml(r.message) + tried);
                return;
            }
            if (r.devices.length > 1) {
                $('searchResult').innerHTML = alertBox('warning', escHtml(r.message)) + '<div class="list-group mt-2">' + r.devices.map((d, i) =>
                    `<button class="list-group-item list-group-item-action" data-i="${i}"><span class="font-mono fw-bold">${escHtml(d.serial_number)}</span> · ${escHtml(d.manufacturer || '')} ${escHtml(d.product_class || '')} <small class="text-muted">(${escHtml(d.genieacs_id)})</small></button>`).join('') + '</div>';
                $('searchResult').querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => checkOnline(r.devices[b.dataset.i])));
                return;
            }
            checkOnline(r.devices[0]);
        } catch (e) {
            $('searchResult').innerHTML = alertBox('danger', escHtml(e.message));
        }
    }

    function renderPreview() {
        const t = TEMPLATES.find(x => String(x.id) === $('templateSelect')?.value);
        if (!t) { $('templatePreview').innerHTML = 'Belum ada template hotspot.'; return; }
        const name = $('nameInput').value.trim() || '{nama}';
        const s = t.summary;
        const ssids = Object.entries(s.ssids).map(([i, n]) => `<span class="badge bg-white text-dark border me-1"><i class="bi bi-wifi text-primary me-1"></i>SSID ${i}: ${escHtml(String(n).split('{nama}').join(name))}</span>`).join('') || '<span class="text-muted">— tidak ada SSID di-bind —</span>';
        $('templatePreview').innerHTML = `
            <div class="fw-semibold mb-2"><i class="bi bi-file-earmark-code me-1"></i>Yang akan diterapkan</div>
            <div class="mb-1">Komentar ONT (GenieACS): <span class="badge bg-info-subtle text-info border">#${escHtml(name)}</span></div>
            <div class="mb-1">WAN: <strong>${escHtml(s.slot.toUpperCase())}</strong> · VLAN <strong>${escHtml(String(s.vlan_id))}</strong> · ${escHtml(s.service_list)}</div>
            <div class="mb-1">LAN di-bind: ${s.lan.length ? s.lan.map(n => 'LAN ' + n).join(', ') : '—'}</div>
            <div class="mb-1">${ssids}</div>
            <div>Keamanan WiFi: <strong>${escHtml(s.security)}</strong></div>`;
    }

    async function apply() {
        const name = $('nameInput').value.trim();
        if (!device) return;
        if (!name) { OntApp.toast('Isi nama pemilik ONT terlebih dahulu.', 'warning'); $('nameInput').focus(); return; }
        if (!confirm(`Terapkan template hotspot ke ONT ${device.serial_number} dengan nama "${name}"?`)) return;
        const btn = $('btnApply');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengirim konfigurasi…';
        try {
            const r = await OntApp.api(URL_APPLY, { method: 'POST', body: { device_id: device.id, template_id: Number($('templateSelect').value), name } });
            (r.warnings || []).forEach(w => OntApp.toast(w, 'warning'));
            pollStatus(r);
        } catch (e) {
            OntApp.toast(e.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Terapkan ke ONT';
        }
    }

    async function pollStatus(job) {
        $('progressCard').style.display = '';
        const link = `<a href="${job.history_url}" class="small">Lihat detail di Riwayat Config ONT</a>`;
        const tick = async () => {
            try {
                const s = await OntApp.api(job.status_url);
                const steps = (s.timeline || []).map(t => `<li class="small"><span class="text-muted">${escHtml((t.at || '').substring(11, 19))}</span> ${escHtml(t.message || t.event)}</li>`).join('');
                $('progressBody').innerHTML = `<div class="mb-2"><span class="badge bg-${escHtml(s.badge)}">${escHtml(s.label)}</span>${s.is_simulation ? ' <span class="badge bg-warning text-dark">SIMULASI</span>' : ''}</div>
                    ${s.error_message ? alertBox('danger', escHtml(s.error_message)) : ''}<ul class="ps-3 mb-2">${steps}</ul>${link}`;
                if (s.final) {
                    const btn = $('btnApply');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send-check me-1"></i> Terapkan ke ONT';
                    OntApp.toast(s.status === 'success' ? 'Konfigurasi hotspot berhasil diterapkan.' : 'Konfigurasi selesai dengan status: ' + s.label, s.status === 'success' ? 'success' : 'warning');
                    return;
                }
            } catch (e) { /* coba lagi */ }
            setTimeout(tick, 2500);
        };
        tick();
    }

    $('btnScan').addEventListener('click', startScan);
    $('btnStopScan').addEventListener('click', stopScan);
    $('btnSearch').addEventListener('click', () => search());
    $('snInput').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
    $('btnApply').addEventListener('click', apply);
    $('nameInput').addEventListener('input', renderPreview);
    $('templateSelect')?.addEventListener('change', renderPreview);
})();
</script>
@endsection
