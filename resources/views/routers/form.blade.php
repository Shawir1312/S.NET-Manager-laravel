@extends('layouts.app')
@php $title = $router->exists ? 'Edit Router' : 'Tambah Router'; @endphp
@section('title', $title)
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('routers.index') }}">Router</a></li>
            <li class="breadcrumb-item active">{{ $title }}</li>
        </ol></nav>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="bi bi-router"></i> {{ $title }}</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ $router->exists ? route('routers.update', $router) : route('routers.store') }}">
            @csrf @if ($router->exists) @method('PUT') @endif
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nama Router <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required value="{{ old('name', $router->name) }}" placeholder="Contoh: Router Kota A">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Router IP Address (API) <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="ip_address" required value="{{ old('ip_address', $router->ip_address) }}" placeholder="10.1.1.18">
                    <div class="form-text">IP untuk test koneksi dan API (Bisa IP lokal router)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">RADIUS NAS IP <span class="text-muted">(Optional)</span></label>
                    <input type="text" class="form-control" name="nas_ip" value="{{ old('nas_ip', $router->nas_ip ?: '0.0.0.0/0') }}" placeholder="0.0.0.0/0">
                    <div class="form-text">IP yang dideteksi FreeRADIUS (Biarkan 0.0.0.0/0 untuk VPN/NAT)</div>
                </div>
                <div class="col-md-12">
                    <label class="form-label">RADIUS Shared Secret <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" class="form-control" name="radius_secret" id="radius_secret" required value="{{ old('radius_secret', $router->radius_secret) }}" placeholder="Shared secret antara router dan FreeRADIUS">
                        <button type="button" class="btn btn-outline-secondary" onclick="const f = document.getElementById('radius_secret'); f.type = f.type === 'password' ? 'text' : 'password';"><i class="bi bi-eye"></i></button>
                    </div>
                    <div class="form-text">Harus sama dengan secret di konfigurasi clients.conf FreeRADIUS</div>
                </div>

                <div class="col-12"><hr class="my-1"><small class="text-muted fw-600">Koneksi RouterOS API (untuk test koneksi & provisioning)</small></div>
                <div class="col-md-4">
                    <label class="form-label">API Username</label>
                    <input type="text" class="form-control" name="api_user" value="{{ old('api_user', $router->api_user ?: 'admin') }}" placeholder="admin">
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Password</label>
                    <input type="password" class="form-control" name="api_password" autocomplete="new-password" placeholder="{{ $router->exists ? 'Kosongkan jika tidak diubah' : 'Password RouterOS' }}" @required(! $router->exists)>
                </div>
                <div class="col-md-4">
                    <label class="form-label">API Port</label>
                    <input type="number" class="form-control" name="api_port" value="{{ old('api_port', $router->api_port ?? 8728) }}" min="1" max="65535">
                </div>
                <div class="col-12">
                    <label class="form-label">Lokasi / Keterangan</label>
                    <input type="text" class="form-control" name="location" value="{{ old('location', $router->location) }}" placeholder="Contoh: Gedung A Lantai 2">
                </div>
                @include('partials.geo-fields', ['lat' => $router->latitude, 'lng' => $router->longitude])

                @if ($genieServers->count() > 1)
                <div class="col-md-6">
                    <label class="form-label">Server GenieACS Cabang Ini</label>
                    <select class="form-select" name="genie_acs_setting_id">
                        <option value="">-- Gunakan Server Default / Utama --</option>
                        @foreach ($genieServers as $g)<option value="{{ $g->id }}" @selected((int) old('genie_acs_setting_id', $router->genie_acs_setting_id) === $g->id)>{{ $g->name }} ({{ $g->nbi_url }})</option>@endforeach
                    </select>
                    <div class="form-text">Hanya muncul bila ada lebih dari satu server GenieACS.</div>
                </div>
                @endif
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="active" @selected(old('status', $router->status ?? 'active') === 'active')>Aktif</option>
                        <option value="inactive" @selected(old('status', $router->status) === 'inactive')>Nonaktif</option>
                    </select>
                </div>
            </div>

            @if ($router->exists)
            <div class="mt-3 p-3 bg-light rounded d-flex align-items-center gap-3" data-test-url="{{ route('routers.test', $router) }}">
                <button type="button" class="btn btn-outline-info btn-sm" onclick="testRouterApi(this.parentElement)"><i class="bi bi-lightning me-1"></i>Test Koneksi API</button>
                <span class="router-status-badge text-muted" style="font-size:.82rem;"></span>
                <span class="router-users-count text-muted" style="font-size:.73rem;"></span>
            </div>
            @endif

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>{{ $router->exists ? 'Simpan Perubahan' : 'Tambah Router' }}</button>
                <a href="{{ route('routers.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>

<div class="card mt-3">
    <div class="card-body">
        <h6 class="fw-700 text-blue mb-2"><i class="bi bi-info-circle me-1"></i>Cara Konfigurasi MikroTik sebagai NAS</h6>
        <ol class="mb-0" style="font-size:.82rem;">
            <li>Di MikroTik: <strong>IP → Hotspot → Hotspot Setup</strong> (atau Radius client)</li>
            <li>Tambah Radius server: <strong>Radius → Add</strong> — Service: <code>hotspot</code>, Address: <em>IP server FreeRADIUS</em>, Secret: <em>shared secret di atas</em></li>
            <li>Di Hotspot Profile: aktifkan <strong>Use RADIUS</strong>, <strong>Accounting</strong>, <strong>NAS Port Type: Wireless-802.11</strong></li>
            <li>IP ini ({{ $router->ip_address ?: 'IP router' }}) otomatis ditambahkan ke tabel <code>nas</code> FreeRADIUS saat Anda menyimpan form ini</li>
        </ol>
    </div>
</div>
</div>
</div>
@include('routers._test_script')
@endsection
