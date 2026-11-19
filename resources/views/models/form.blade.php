@extends('layouts.app')
@section('title', $model->exists ? 'Kelola Model '.$model->model_name : 'Model Baru')
@php($cap = $model->capability)
@section('content')
@include('partials.errors')
<form method="POST" action="{{ $model->exists ? route('models.update', $model) : route('models.store') }}" class="card mb-3">
    @csrf @if ($model->exists) @method('PUT') @endif
    <div class="card-header">Identitas & Kemampuan</div>
    <div class="card-body row g-3">
        <div class="col-md-3"><label class="form-label">Vendor</label><select class="form-select" name="vendor">
            @foreach (\App\Enums\Vendor::cases() as $v)<option value="{{ $v->value }}" @selected(old('vendor', $model->vendor?->value) === $v->value)>{{ $v->label() }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Nama Model</label><input class="form-control" name="model_name" value="{{ old('model_name', $model->model_name) }}" required></div>
        <div class="col-md-3"><label class="form-label">Product Class (GenieACS)</label><input class="form-control font-monospace" name="product_class" value="{{ old('product_class', $model->product_class) }}" required></div>
        <div class="col-md-3"><label class="form-label">Pola Firmware</label><input class="form-control font-monospace" name="firmware_pattern" value="{{ old('firmware_pattern', $model->firmware_pattern) }}" required><div class="form-text">* = semua, atau regex mis. ^RP43</div></div>
        <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status">
            @foreach (\App\Enums\ModelSupportStatus::cases() as $s)<option value="{{ $s->value }}" @selected(old('status', $model->status?->value) === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Metode Binding</label><select class="form-select" name="binding_method">
            @foreach (\App\Enums\BindingMethod::cases() as $b)<option value="{{ $b->value }}" @selected(old('binding_method', $cap?->binding_method?->value ?? 'unknown') === $b->value)>{{ $b->value }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label">Slot PPPoE</label><select class="form-select" name="pppoe_slot">
            <option value="ppp" @selected(old('pppoe_slot', $cap?->extra['pppoe_slot'] ?? 'ppp') === 'ppp')>WAN PPP</option>
            <option value="ip" @selected(old('pppoe_slot', $cap?->extra['pppoe_slot'] ?? 'ppp') === 'ip')>WAN IP</option></select></div>
        <div class="col-md-3"><label class="form-label" for="nws">Cara membuat WAN baru</label><select class="form-select" name="new_wan_strategy" id="nws">
            <option value="new_wcd" @selected(old('new_wan_strategy', $cap?->extra['new_wan_strategy'] ?? 'new_wcd') === 'new_wcd')>WANConnectionDevice baru</option>
            <option value="existing_wcd" @selected(old('new_wan_strategy', $cap?->extra['new_wan_strategy'] ?? 'new_wcd') === 'existing_wcd')>Di WANConnectionDevice yang ada</option></select>
            <div class="form-text">FiberHome: baru · ZTE F609: yang ada</div></div>
        <div class="col-md-3"><label class="form-label">VLAN min / max</label><div class="input-group">
            <input class="form-control" type="number" name="vlan_min" value="{{ old('vlan_min', $cap?->vlan_min ?? 1) }}"><input class="form-control" type="number" name="vlan_max" value="{{ old('vlan_max', $cap?->vlan_max ?? 4094) }}"></div></div>
        <div class="col-6 col-md-2"><label class="form-label">Maks WAN IP</label><input class="form-control" type="number" name="max_wan_ip" value="{{ old('max_wan_ip', $cap?->max_wan_ip) }}" placeholder="?"></div>
        <div class="col-6 col-md-2"><label class="form-label">Maks WAN PPP</label><input class="form-control" type="number" name="max_wan_ppp" value="{{ old('max_wan_ppp', $cap?->max_wan_ppp) }}" placeholder="?"></div>
        <div class="col-6 col-md-2"><label class="form-label">Port LAN</label><input class="form-control" type="number" name="lan_ports" value="{{ old('lan_ports', $cap?->lan_ports) }}"></div>
        <div class="col-6 col-md-2"><label class="form-label">Jumlah SSID</label><input class="form-control" type="number" name="ssid_count" value="{{ old('ssid_count', $cap?->ssid_count) }}"></div>
        <div class="col-md-4 d-flex flex-column justify-content-end">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="supports_bridge" value="1" id="sb" @checked(old('supports_bridge', $cap?->supports_bridge))><label class="form-check-label" for="sb">Bridge sudah diuji di ONT asli</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="supports_pppoe" value="1" id="sp" @checked(old('supports_pppoe', $cap?->supports_pppoe))><label class="form-check-label" for="sp">PPPoE sudah diuji di ONT asli</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="wan_name_auto" value="1" id="wna" @checked(old('wan_name_auto', $cap?->extra['wan_name_auto'] ?? false))><label class="form-check-label" for="wna">Nama WAN dibuat otomatis oleh ONT (jangan kirim Name)</label></div>
        </div>
        <div class="col-12"><label class="form-label">Catatan</label><textarea class="form-control" name="notes" rows="2">{{ old('notes', $model->notes) }}</textarea></div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary"><i class="bi bi-save"></i> Simpan model</button></div>
</form>

@if ($model->exists)
<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <span class="me-auto">Mapping Parameter ({{ $model->parameterMappings->count() }})</span>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="checkAllVerified"><i class="bi bi-check2-all"></i> Centang semua</button>
        <button class="btn btn-sm btn-primary" form="verifiedForm"><i class="bi bi-save"></i> Simpan centang verified</button>
    </div>
    <div class="card-body border-bottom small text-body-secondary">
        Placeholder: <code>{wcd}</code> WANConnectionDevice, <code>{i}</code> instance WAN, <code>{ssid}</code> WLANConfiguration.
        Verifikasi otomatis: <span class="badge text-bg-info">path</span> = path ditemukan saat ONT dicari,
        <span class="badge text-bg-success">write</span> = ditulis & dibaca ulang cocok setelah job produksi,
        <span class="badge text-bg-secondary">manual</span> = ditandai admin.<br>
        Kunci binding: <code>wan.ip.binding</code> / <code>wan.ppp.binding</code> (string list) atau <code>wan.ppp.bind_lan1</code>… (flag).
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 small">
        <thead><tr><th>Key</th><th>Path</th><th>Tipe</th><th>Verified</th><th>Sumber</th><th></th></tr></thead>
        <tbody>
        @foreach ($model->parameterMappings as $m)
            <tr>
                <td class="font-monospace">{{ $m->key }}<input type="hidden" name="key" value="{{ $m->key }}" form="map{{ $m->id }}"></td>
                <td><input class="form-control form-control-sm font-monospace" name="path_template" value="{{ $m->path_template }}" form="map{{ $m->id }}" aria-label="path {{ $m->key }}"></td>
                <td><select class="form-select form-select-sm" name="value_type" form="map{{ $m->id }}" aria-label="tipe {{ $m->key }}">@foreach (['xsd:string','xsd:boolean','xsd:unsignedInt','xsd:int','xsd:dateTime'] as $t)<option @selected($m->value_type === $t)>{{ $t }}</option>@endforeach</select></td>
                <td class="text-nowrap"><input type="hidden" name="verified" value="{{ $m->verified ? 1 : 0 }}" form="map{{ $m->id }}">
                    <input class="form-check-input js-verified" type="checkbox" name="verified_ids[]" value="{{ $m->id }}" @checked($m->verified) form="verifiedForm" aria-label="verified {{ $m->key }}">
                    @if ($m->verified)<span class="badge text-bg-{{ ['write' => 'success', 'path' => 'info', 'manual' => 'secondary'][$m->verification_level] ?? 'secondary' }}" title="{{ $m->verified_device }}">{{ $m->verification_level ?? 'manual' }}</span>@endif</td>
                <td class="text-body-secondary" style="max-width:16rem">{{ $m->source }}</td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary" title="Simpan" form="map{{ $m->id }}"><i class="bi bi-check-lg"></i></button>
                    <button class="btn btn-sm btn-outline-danger" title="Hapus" form="del{{ $m->id }}"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        @endforeach
        <tr class="table-light">
            <td><input class="form-control form-control-sm font-monospace" name="key" placeholder="wan.ppp.binding" required form="mapNew" aria-label="key baru"></td>
            <td><input class="form-control form-control-sm font-monospace" name="path_template" placeholder="InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{wcd}...." required form="mapNew" aria-label="path baru"></td>
            <td><select class="form-select form-select-sm" name="value_type" form="mapNew" aria-label="tipe baru">@foreach (['xsd:string','xsd:boolean','xsd:unsignedInt','xsd:int'] as $t)<option>{{ $t }}</option>@endforeach</select></td>
            <td><input class="form-check-input" type="checkbox" name="verified" value="1" form="mapNew" aria-label="verified baru"></td>
            <td><input class="form-control form-control-sm" name="source" placeholder="mis. dicek di ONT SN xxx" form="mapNew" aria-label="sumber"></td>
            <td><button class="btn btn-sm btn-success" form="mapNew"><i class="bi bi-plus-lg"></i></button></td>
        </tr>
        </tbody>
    </table></div>
</div>
@foreach ($model->parameterMappings as $m)
    <form id="map{{ $m->id }}" method="POST" action="{{ route('models.mappings.save', $model) }}">@csrf</form>
    <form id="del{{ $m->id }}" class="confirm-delete" method="POST" action="{{ route('models.mappings.delete', [$model, $m]) }}">@csrf @method('DELETE')</form>
@endforeach
<form id="mapNew" method="POST" action="{{ route('models.mappings.save', $model) }}">@csrf</form>
<form id="verifiedForm" method="POST" action="{{ route('models.mappings.verified', $model) }}">@csrf</form>
<script>
    document.getElementById('checkAllVerified').addEventListener('click', () => document.querySelectorAll('.js-verified').forEach(c => { c.checked = true; }));
</script>
@endif
@endsection
