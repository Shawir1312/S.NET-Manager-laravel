@extends('layouts.app')
@section('title', $template->exists ? 'Edit Template ONT' : 'Template ONT Baru')
@php($p = $template->payload ?? [])
@section('content')
@include('partials.errors')
<form method="POST" action="{{ $template->exists ? route('templates.update', $template) : route('templates.store') }}" class="card">
    @csrf @if ($template->exists) @method('PUT') @endif
    <div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">Nama *</label><input class="form-control" name="name" value="{{ old('name', $template->name) }}" required maxlength="100"></div>
        <div class="col-md-6"><label class="form-label">Jenis layanan *</label>
            <select class="form-select" name="service_type">
                @foreach (\App\Enums\ServiceType::cases() as $s)
                    <option value="{{ $s->value }}" @selected(old('service_type', $template->service_type?->value) === $s->value)>{{ $s->label() }}</option>
                @endforeach
            </select></div>
        <div class="col-12"><label class="form-label">Deskripsi</label><input class="form-control" name="description" value="{{ old('description', $template->description) }}" maxlength="500"></div>
        <div class="col-md-3"><label class="form-label">WAN Slot</label>
            <select class="form-select" name="slot">
                <option value="ip" @selected(old('slot', $p['wan']['slot'] ?? 'ip') === 'ip')>WAN IP</option>
                <option value="ppp" @selected(old('slot', $p['wan']['slot'] ?? '') === 'ppp')>WAN PPP</option>
            </select><div class="form-text">PPPoE selalu memakai slot PPP.</div></div>
        <div class="col-md-3"><label class="form-label">VLAN ID *</label><input class="form-control" type="number" min="1" max="4094" name="vlan_id" value="{{ old('vlan_id', $p['wan']['vlan_id'] ?? '') }}" required></div>
        <div class="col-md-3"><label class="form-label">VLAN Priority</label><input class="form-control" type="number" min="0" max="7" name="vlan_priority" value="{{ old('vlan_priority', $p['wan']['vlan_priority'] ?? '') }}"></div>
        <div class="col-md-3"><label class="form-label">Service List</label>
            <select class="form-select" name="service_list">
                @foreach (\App\Services\ONT\Mappers\WANMapper::SERVICE_LISTS as $sl)
                    <option value="{{ $sl }}" @selected(old('service_list', $p['wan']['service_list'] ?? 'INTERNET') === $sl)>{{ $sl }}</option>
                @endforeach
            </select></div>
        <div class="col-md-6"><label class="form-label">WAN Name</label><input class="form-control" name="wan_name" value="{{ old('wan_name', $p['wan']['name'] ?? '') }}" maxlength="64" placeholder="otomatis bila kosong"></div>
        <div class="col-md-6 d-flex align-items-end">
            <div class="form-check"><input type="hidden" name="nat_enabled" value="0"><input class="form-check-input" type="checkbox" name="nat_enabled" value="1" id="nat" @checked(old('nat_enabled', $p['wan']['nat_enabled'] ?? true))><label class="form-check-label" for="nat">NAT Enable (PPPoE)</label></div>
        </div>
        <div class="col-md-4"><div class="form-label">LAN Binding</div>
            @foreach (range(1, 4) as $n)
                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="lan[]" value="{{ $n }}" id="lan{{ $n }}" @checked(in_array($n, old('lan', $p['binding']['lan'] ?? [])))><label class="form-check-label" for="lan{{ $n }}">LAN {{ $n }}</label></div>
            @endforeach</div>
        <div class="col-md-8"><div class="form-label">WLAN Binding</div>
            @foreach (range(1, 8) as $n)
                <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="wlan[]" value="{{ $n }}" id="wlan{{ $n }}" @checked(in_array($n, old('wlan', $p['binding']['wlan'] ?? [])))><label class="form-check-label" for="wlan{{ $n }}">SSID {{ $n }}</label></div>
            @endforeach
            <div class="form-text">Interface yang tidak ada di ONT otomatis diabaikan saat template dipakai.</div></div>
        @php($ssidNames = collect($p['ssids'] ?? [])->pluck('ssid_name', 'index'))
        <div class="col-12"><div class="form-label">Nama SSID (opsional, untuk SSID yang di-bind)</div>
            <div class="row g-2">
                @foreach (range(1, 8) as $n)
                    <div class="col-6 col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">SSID {{ $n }}</span>
                        <input class="form-control" name="ssid_names[{{ $n }}]" maxlength="32" value="{{ old('ssid_names.'.$n, $ssidNames[$n] ?? '') }}"></div></div>
                @endforeach
            </div>
            <div class="form-text">Kosongkan = pakai nama WiFi pelanggan. <code>{nama}</code> diganti nama depan pelanggan, mis. <code>S.NET - {nama}</code>. Hotspot: isi nama tetap, mis. <code>S.NET @Hotspot</code> atau <code>S.NET - {nama}</code> ({nama} = nama yang diisi saat setting ONT hotspot). Password PPPoE/WiFi pelanggan tidak pernah disimpan di template.</div></div>
        @php($wifi = $p['wifi'] ?? [])
        <div class="col-12" id="hotspotWifiBox">
            <div class="p-3 rounded border bg-light">
                <div class="form-label fw-semibold mb-2"><i class="bi bi-wifi me-1 text-primary"></i>Keamanan WiFi Hotspot</div>
                @foreach (['keep' => 'Biarkan seperti di ONT (tidak diubah)', 'open' => 'Tanpa password (WiFi terbuka)', 'password' => 'Pakai password (WPA2)'] as $v => $l)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="radio" name="wifi_security" value="{{ $v }}" id="ws_{{ $v }}" @checked(old('wifi_security', $wifi['security'] ?? 'keep') === $v)>
                        <label class="form-check-label" for="ws_{{ $v }}">{{ $l }}</label>
                    </div>
                @endforeach
                <div class="mt-2" id="wifiKeyBox" style="max-width: 360px;">
                    <input class="form-control" type="password" name="wifi_key" minlength="8" maxlength="63" autocomplete="new-password"
                           placeholder="{{ filled($wifi['key_enc'] ?? null) ? 'Tersimpan (terenkripsi) — kosongkan jika tidak diubah' : 'Password WiFi hotspot, min. 8 karakter' }}">
                </div>
                <div class="form-text">Berlaku untuk semua SSID yang di-bind di template hotspot ini. Password disimpan terenkripsi.</div>
            </div>
        </div>
        <script>
            (() => {
                const type = document.querySelector('[name=service_type]'), box = document.getElementById('hotspotWifiBox'), keyBox = document.getElementById('wifiKeyBox');
                const sync = () => {
                    box.style.display = type.value === 'bridge' ? '' : 'none';
                    keyBox.style.display = document.querySelector('[name=wifi_security]:checked')?.value === 'password' ? '' : 'none';
                };
                type.addEventListener('change', sync);
                document.querySelectorAll('[name=wifi_security]').forEach(r => r.addEventListener('change', sync));
                sync();
            })();
        </script>
        <div class="col-12"><div class="form-check"><input type="hidden" name="is_default" value="0"><input class="form-check-input" type="checkbox" name="is_default" value="1" id="def" @checked(old('is_default', $template->is_default))><label class="form-check-label" for="def">Jadikan template default</label></div></div>
    </div>
    <div class="card-footer d-flex gap-2 justify-content-end">
        <a href="{{ route('templates.index') }}" class="btn btn-outline-secondary">Batal</a>
        <button class="btn btn-primary"><i class="bi bi-save"></i> Simpan</button>
    </div>
</form>
@endsection
