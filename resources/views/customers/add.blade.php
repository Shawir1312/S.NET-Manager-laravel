@extends(request()->routeIs('field.*') ? 'layouts.field' : 'layouts.app')
@section('title', 'Tambah Pelanggan')
@php($field = request()->routeIs('field.*'))
@section('content')
<div class="row justify-content-center">
    <div class="col-12 col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header fw-semibold"><span class="badge bg-primary rounded-circle me-2">1</span>Scan / isi SN ONT</div>
            <div class="card-body">
                <div class="input-group input-group-lg mb-2">
                    <input type="text" class="form-control font-monospace text-uppercase" id="snInput" placeholder="Contoh: HWTC79FC699D" autocomplete="off" autocapitalize="characters" maxlength="64" aria-label="SN ONT">
                    <button class="btn btn-outline-dark" type="button" id="btnScan" title="Scan QR / barcode" aria-label="Scan SN pakai kamera"><i class="bi bi-qr-code-scan"></i></button>
                </div>
                <div id="scanBox" class="mb-2" style="display:none;">
                    <div id="qrReader" class="rounded overflow-hidden border" style="max-width:420px; margin:0 auto;"></div>
                    <button class="btn btn-sm btn-outline-danger w-100 mt-1" type="button" id="btnStopScan">Tutup Kamera</button>
                </div>
                <div id="snCheck" class="small" aria-live="polite"></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header fw-semibold"><span class="badge bg-primary rounded-circle me-2">2</span>Pilih jenis pelanggan</div>
            <div class="card-body d-grid gap-2">
                @can('operate')
                <a class="btn btn-lg btn-success text-start js-go" data-url="{{ route($field ? 'field.customers.create' : 'customers.create') }}" href="{{ route($field ? 'field.customers.create' : 'customers.create') }}">
                    <i class="bi bi-house-door me-2"></i>Pelanggan Rumahan (PPPoE)
                    <div class="small fw-normal opacity-75">Data pelanggan, paket & tagihan, akun PPPoE. Hotspot ikut bila ada di template PPPoE.</div>
                </a>
                @endcan
                @can('configure-ont')
                <a class="btn btn-lg btn-outline-primary text-start js-go" data-url="{{ route($field ? 'field.hotspot' : 'ont-hotspot.index') }}" href="{{ route($field ? 'field.hotspot' : 'ont-hotspot.index') }}">
                    <i class="bi bi-broadcast-pin me-2"></i>Hotspot saja
                    <div class="small fw-normal opacity-75">Isi nama pemilik (jadi tag ONT di GenieACS), semua setting dari template hotspot.</div>
                </a>
                @endcan
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="{{ asset('assets/sn-scan.js') }}?v={{ filemtime(public_path('assets/sn-scan.js')) }}"></script>
<script>
(() => {
    const $ = id => document.getElementById(id);
    let timer = null, seq = 0;

    // Tombol jenis pelanggan membawa SN ke langkah berikutnya (?sn=...).
    function syncLinks() {
        const sn = $('snInput').value.trim().toUpperCase();
        document.querySelectorAll('.js-go').forEach(a => { a.href = a.dataset.url + (sn ? '?sn=' + encodeURIComponent(sn) : ''); });
    }

    async function check() {
        const sn = $('snInput').value.trim().toUpperCase(), box = $('snCheck'), my = ++seq;
        if (sn.length < 6) { box.innerHTML = ''; return; }
        box.innerHTML = '<span class="text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Mengecek SN di GenieACS…</span>';
        try {
            const r = await OntApp.api(@json(route('api.ont.locate')), { method: 'POST', body: { serial_number: sn } });
            if (my !== seq) return;
            box.innerHTML = r.found.length === 0
                ? '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>SN tidak ditemukan di GenieACS mana pun. Cek SN atau pastikan ONT sudah terhubung ke ACS.</span>'
                : r.found.map(d => `<div class="${d.online ? 'text-success' : 'text-warning'}"><i class="bi ${d.online ? 'bi-check-circle' : 'bi-exclamation-circle'} me-1"></i>`
                    + `Ada di <strong>${escHtml(d.server)}</strong> · ${escHtml(d.product)} · ${d.online ? 'Online' : 'Offline'}</div>`).join('');
        } catch (e) { if (my === seq) box.innerHTML = '<span class="text-danger">' + escHtml(e.message) + '</span>'; }
    }

    $('snInput').addEventListener('input', () => { syncLinks(); clearTimeout(timer); timer = setTimeout(check, 700); });
    $('btnScan').addEventListener('click', () => SnScan.start($('scanBox'), 'qrReader', (decoded, c) => { $('snInput').value = c[0] || decoded.trim(); syncLinks(); check(); }));
    $('btnStopScan').addEventListener('click', () => SnScan.stop($('scanBox')));
})();
</script>
@endpush
