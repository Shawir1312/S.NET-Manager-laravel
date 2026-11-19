@extends(request()->routeIs('field.*') ? 'layouts.field' : 'layouts.app')
@section('title', $customer->exists ? 'Edit Pelanggan' : 'Tambah Pelanggan PPPoE')
@section('content')
@include('partials.errors')
@php($edit = $customer->exists)
<form method="POST" action="{{ $edit ? route('customers.update', $customer) : route('customers.store') }}" autocomplete="off" id="customerForm">
    @if (request()->routeIs('field.*'))<input type="hidden" name="from_field" value="1">@endif
    @csrf @if ($edit) @method('PUT') @endif
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><i class="bi bi-person"></i> Data pelanggan</div><div class="card-body row g-3">
                <div class="col-12"><label class="form-label">Router *</label><select class="form-select" name="router_id" id="routerId" required>
                    @foreach ($routers as $r)<option value="{{ $r->id }}" @selected((int) old('router_id', $customer->router_id) === $r->id)>{{ $r->name }}</option>@endforeach</select>
                    @if ($routers->isEmpty())<div class="form-text text-danger">Belum ada router. Tambahkan di menu Router & NAS.</div>@endif</div>
                <div class="col-12"><label class="form-label">Nama lengkap *</label><input class="form-control form-control-lg" name="full_name" id="fullName" value="{{ old('full_name', $customer->full_name) }}" required maxlength="150"></div>
                <div class="col-md-6"><label class="form-label">No. WhatsApp</label><input class="form-control" name="phone" inputmode="tel" value="{{ old('phone', $customer->phone) }}" placeholder="08xxxxxxxxxx"></div>
                <div class="col-md-6 field-hide"><label class="form-label">Status</label><select class="form-select" name="status">
                    @foreach (['active' => 'Aktif', 'isolated' => 'Isolir', 'suspended' => 'Suspend'] as $v => $l)<option value="{{ $v }}" @selected(old('status', $customer->status) === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-12"><label class="form-label">Alamat</label><textarea class="form-control" name="address" rows="2">{{ old('address', $customer->address) }}</textarea></div>
                <div class="col-12 field-hide"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2" placeholder="Port ODP, teknisi, dll.">{{ old('notes', $customer->notes) }}</textarea></div>
            </div></div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><i class="bi bi-speedometer"></i> Paket & tagihan</div><div class="card-body row g-3">
                <div class="col-12"><label class="form-label">Profil PPPoE *</label><input class="form-control" name="profile" list="profileList" value="{{ old('profile', $customer->profile) }}" required>
                    <datalist id="profileList">@foreach ($profiles as $p)<option value="{{ $p }}">@endforeach</datalist></div>
                <div class="col-md-6"><label class="form-label">Harga bulanan (Rp)</label><input class="form-control" type="number" min="0" name="monthly_price" id="price" value="{{ old('monthly_price', $customer->monthly_price) }}"></div>
                <div class="col-md-6"><label class="form-label">Jatuh tempo (tgl) *</label><input class="form-control" type="number" min="1" max="28" name="due_day" value="{{ old('due_day', $customer->due_day) }}" required></div>
                <div class="col-12 field-hide"><div class="form-check form-switch"><input type="hidden" name="is_free" value="0"><input class="form-check-input" type="checkbox" role="switch" name="is_free" value="1" id="isFree" @checked(old('is_free', $customer->is_free))><label class="form-check-label" for="isFree">Pelanggan gratis (tanpa tagihan)</label></div></div>

                <div class="col-12"><hr class="my-1"><div class="small fw-semibold text-body-secondary">AKUN PPPoE & PORTAL</div></div>
                <div class="col-md-6"><label class="form-label">Username PPPoE *</label><input class="form-control font-monospace" name="pppoe_username" id="pppoeUser" value="{{ old('pppoe_username', $customer->pppoe_username) }}" required></div>
                <div class="col-md-6"><label class="form-label">Password PPPoE {{ $edit ? '(kosong = tetap)' : '' }}</label>
                    <div class="input-group"><input class="form-control font-monospace" name="pppoe_password" id="pppoePass" value="{{ old('pppoe_password', $suggestedPassword) }}" autocomplete="new-password">
                    <button class="btn btn-outline-secondary" type="button" id="btnRandom" title="Acak"><i class="bi bi-shuffle"></i></button></div></div>
                <div class="col-md-6 field-hide"><label class="form-label">Username portal</label><input class="form-control font-monospace" name="portal_username" id="portalUser" value="{{ old('portal_username', $customer->portal_username) }}" placeholder="= username PPPoE"></div>
                <div class="col-md-6 field-hide"><label class="form-label">Password portal {{ $edit ? '(kosong = tetap)' : '' }}</label><input class="form-control font-monospace" name="portal_password" autocomplete="new-password" placeholder="{{ $edit ? '' : '= password PPPoE' }}"></div>
            </div></div>
        </div>

        <div class="col-12">
            <div class="card"><div class="card-header"><i class="bi bi-router"></i> ONT & WiFi</div><div class="card-body row g-3">
                <div class="col-md-4"><label class="form-label">SN ONT</label>
                    <div class="input-group"><input class="form-control font-monospace text-uppercase" name="ont_sn" id="ontSn" value="{{ old('ont_sn', $customer->ont_sn) }}" placeholder="FHTT… / ZTEG… / HWTC…">
                    <button class="btn btn-outline-dark" type="button" id="btnScanSn" title="Scan QR / barcode SN" aria-label="Scan SN pakai kamera"><i class="bi bi-qr-code-scan"></i></button></div>
                    <div id="snCheck" class="small mt-1" aria-live="polite"></div>
                    <div id="snScanBox" class="mt-2" style="display:none;"><div id="snReader" class="rounded overflow-hidden border"></div>
                        <button class="btn btn-sm btn-outline-danger w-100 mt-1" type="button" id="btnStopSn">Tutup Kamera</button></div></div>
                <div class="col-md-2"><label class="form-label">VLAN PPPoE</label><input class="form-control" type="number" min="1" max="4094" name="ont_vlan" id="ontVlan" value="{{ old('ont_vlan', $customer->ont_vlan ?: null) }}" placeholder="ikut template"></div>
                <div class="col-md-3"><label class="form-label">Nama WiFi</label><input class="form-control" name="ont_wifi_ssid" id="wifiSsid" maxlength="32" value="{{ old('ont_wifi_ssid', $customer->ont_wifi_ssid) }}" placeholder="otomatis: S.NET - Nama"></div>
                <div class="col-md-3"><label class="form-label">Password WiFi (min. 8)</label><input class="form-control font-monospace" name="ont_wifi_pass" minlength="8" maxlength="63" value="{{ old('ont_wifi_pass') }}" placeholder="{{ $edit ? 'kosong = tetap' : '= password PPPoE' }}" autocomplete="new-password"></div>

                @can('configure-ont')
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-md-4"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="push_ont" value="1" id="pushOnt" @checked(old('push_ont', ! $edit))>
                        <label class="form-check-label fw-semibold" for="pushOnt">Konfigurasi ONT otomatis</label></div>
                        <div class="form-text">Pakai mesin provisioning (preview, backup, verifikasi, rollback).</div></div>
                    <div class="col-md-4 field-hide"><label class="form-label">Template PPPoE</label><select class="form-select" name="pppoe_template_id"><option value="">Template default</option>
                        @foreach ($templates->where('service_type', \App\Enums\ServiceType::Pppoe) as $t)<option value="{{ $t->id }}" @selected((int) old('pppoe_template_id') === $t->id)>{{ $t->name }}{{ filled($t->payload['hotspot'] ?? null) ? ' + Hotspot' : '' }}{{ $t->is_default ? ' (default)' : '' }}</option>@endforeach</select></div>
                    <div class="col-md-4 field-hide d-flex align-items-end"><div class="form-text"><i class="bi bi-broadcast-pin me-1"></i>Hotspot ikut terpasang bila template PPPoE punya seksi Hotspot.</div></div>
                @endcan
                @unless ($edit)
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="send_welcome" value="1" id="sendWelcome" @checked(old('send_welcome', true))><label class="form-check-label" for="sendWelcome">Kirim WA selamat datang + akun portal</label></div></div>
                @endunless
            </div></div>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end mt-3 position-sticky bottom-0 py-2 bg-body">
        <a href="{{ request()->routeIs('field.*') ? route('field.home') : ($edit ? route('customers.show', $customer) : route('customers.index')) }}" class="btn btn-outline-secondary">Batal</a>
        <button class="btn btn-primary px-4"><i class="bi bi-save"></i> Simpan</button>
    </div>
</form>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="{{ asset('assets/sn-scan.js') }}?v={{ filemtime(public_path('assets/sn-scan.js')) }}"></script>
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    // Cek langsung: SN ini terdaftar di server GenieACS mana & online atau tidak.
    let snTimer = null, snSeq = 0;
    async function checkSn() {
        const sn = $('ontSn').value.trim().toUpperCase(), box = $('snCheck'), seq = ++snSeq;
        if (sn.length < 6) { box.innerHTML = ''; return; }
        box.innerHTML = '<span class="text-body-secondary"><span class="spinner-border spinner-border-sm me-1"></span>Mengecek SN di GenieACS…</span>';
        try {
            const r = await OntApp.api(@json(route('api.ont.locate')), { method: 'POST', body: { serial_number: sn } });
            if (seq !== snSeq) return;
            box.innerHTML = r.found.length === 0
                ? '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>SN tidak ditemukan di GenieACS mana pun. Cek SN atau pastikan ONT sudah terhubung ke ACS.</span>'
                : r.found.map(d => `<div class="${d.online ? 'text-success' : 'text-warning'}"><i class="bi ${d.online ? 'bi-check-circle' : 'bi-exclamation-circle'} me-1"></i>`
                    + `Ada di <strong>${escHtml(d.server)}</strong> · ${escHtml(d.product)} · ${d.online ? 'Online' : 'Offline (inform terakhir ' + (d.last_inform ? new Date(d.last_inform).toLocaleString('id-ID') : '-') + ')'}</div>`).join('');
        } catch (e) { if (seq === snSeq) box.innerHTML = '<span class="text-danger">' + escHtml(e.message) + '</span>'; }
    }
    $('ontSn').addEventListener('input', () => { clearTimeout(snTimer); snTimer = setTimeout(checkSn, 700); });
    if ($('ontSn').value) checkSn();
    $('btnScanSn').addEventListener('click', () => SnScan.start($('snScanBox'), 'snReader', (decoded, c) => { $('ontSn').value = c[0] || decoded.trim(); checkSn(); }));
    $('btnStopSn').addEventListener('click', () => SnScan.stop($('snScanBox')));
    const isEdit = @json($edit);
    const suffix = @json(app(\App\Services\Settings\SnetSettings::class)->get('pppoe', 'ont_username_suffix', '@snet'));
    const price = $('price');
    const syncFree = () => { price.readOnly = $('isFree').checked; if ($('isFree').checked) price.value = 0; };
    $('isFree').addEventListener('change', syncFree); syncFree();
    $('btnRandom').addEventListener('click', () => { $('pppoePass').value = String(Math.floor(10000000 + Math.random() * 90000000)); });
    if (!isEdit) {
        // Username otomatis dari nama, sama seperti S.NET lama: "budisantoso@snet".
        $('fullName').addEventListener('input', (e) => {
            const clean = e.target.value.toLowerCase().replace(/[^a-z0-9]/g, '').slice(0, 20);
            if (!$('pppoeUser').dataset.touched) $('pppoeUser').value = clean ? clean + suffix : '';
            if (!$('portalUser').dataset.touched) $('portalUser').value = clean;
        });
        ['pppoeUser', 'portalUser'].forEach(id => $(id).addEventListener('input', () => { $(id).dataset.touched = '1'; }));
    }
})();
</script>
@endpush
