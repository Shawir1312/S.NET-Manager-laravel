@extends('layouts.app')
@php $title = $profile->exists ? 'Edit Profil' : 'Tambah Profil'; @endphp
@section('title', $title)
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $title }}</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('hotspot-profiles.index') }}">Profil</a></li>
            <li class="breadcrumb-item active">{{ $title }}</li>
        </ol></nav>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
@php $units = ['minutes' => 'Menit', 'hours' => 'Jam', 'days' => 'Hari']; @endphp
<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="bi bi-collection"></i> {{ $title }}</h5></div>
    <div class="card-body">
        <form method="POST" id="profileForm" action="{{ $profile->exists ? route('hotspot-profiles.update', $profile) : route('hotspot-profiles.store') }}">
            @csrf @if ($profile->exists) @method('PUT') @endif
            <div class="row g-3">
                @unless ($profile->exists)
                <div class="col-12 mb-2">
                    <label class="form-label text-primary fw-bold"><i class="bi bi-magic me-1"></i>Isi Otomatis dari Template</label>
                    <select class="form-select border-primary bg-primary bg-opacity-10" id="profileTemplate" onchange="applyTemplate(this.value)">
                        <option value="">-- Pilih Template Profil (Opsional) --</option>
                        <option value="1jam">Paket 1 Jam (Rp 2.000)</option>
                        <option value="3jam">Paket 3 Jam (Rp 3.000)</option>
                        <option value="5jam">Paket 5 Jam (Rp 5.000)</option>
                        <option value="12jam">Paket 12 Jam (Rp 10.000)</option>
                        <option value="1hari">Paket 1 Hari / 24 Jam (Rp 15.000)</option>
                        <option value="1minggu">Paket 1 Minggu (Rp 30.000)</option>
                        <option value="1bulan">Paket 1 Bulan (Rp 100.000)</option>
                    </select>
                </div>
                <script>
                function applyTemplate(val) {
                    const t = {
                        '1jam': {n:'1 Jam', d:'1 JAM', vv:1, vu:'days', dv:1, du:'hours', p:2000},
                        '3jam': {n:'3 Jam', d:'3 JAM', vv:1, vu:'days', dv:3, du:'hours', p:3000},
                        '5jam': {n:'5 Jam', d:'5 JAM', vv:1, vu:'days', dv:5, du:'hours', p:5000},
                        '12jam': {n:'12 Jam', d:'12 JAM', vv:1, vu:'days', dv:12, du:'hours', p:10000},
                        '1hari': {n:'1 Hari', d:'1 HARI', vv:1, vu:'days', dv:24, du:'hours', p:15000},
                        '1minggu': {n:'1 Minggu', d:'1 MINGGU', vv:7, vu:'days', dv:7, du:'days', p:30000},
                        '1bulan': {n:'1 Bulan', d:'1 BULAN', vv:30, vu:'days', dv:30, du:'days', p:100000}
                    }[val];
                    if (!t) return;
                    const f = document.getElementById('profileForm'), set = (n, v) => f.querySelector('[name="' + n + '"]').value = v;
                    set('name', t.n); set('display_name', t.d); set('validity_value', t.vv); set('validity_unit', t.vu);
                    set('duration_value', t.dv); set('duration_unit', t.du); set('price', t.p); set('quota_mb', 0);
                    set('rate_up', '2M'); set('rate_down', '3M'); set('reseller_percent', 20); set('include_in_sales', '1');
                }
                </script>
                @endunless

                <div class="col-md-6">
                    <label class="form-label">Nama Profil <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required value="{{ old('name', $profile->name) }}" placeholder="Contoh: Paket 1 Jam">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nama Tampilan (pada voucher)</label>
                    <input type="text" class="form-control" name="display_name" value="{{ old('display_name', $profile->display_name) }}" placeholder="Contoh: 1 JAM - 5MB">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Masa Aktif <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="validity_value" min="1" required value="{{ old('validity_value', $profile->validity_value ?? 30) }}">
                        <select class="form-select" style="max-width: 120px;" name="validity_unit">@foreach ($units as $k => $l)<option value="{{ $k }}" @selected(old('validity_unit', $profile->validity_unit ?? 'days') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div class="form-text">→ Batas absolut sejak pertama login (Expired_at)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Durasi Pakai (0 = Unlimited)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="duration_value" min="0" required value="{{ old('duration_value', $profile->duration_value ?? 30) }}">
                        <select class="form-select" style="max-width: 120px;" name="duration_unit">@foreach ($units as $k => $l)<option value="{{ $k }}" @selected(old('duration_unit', $profile->duration_unit ?? 'hours') === $k)>{{ $l }}</option>@endforeach</select>
                    </div>
                    <div class="form-text">→ Total kuota waktu online (Session-Timeout)</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Harga Jual (Rp)</label>
                    <input type="number" class="form-control" name="price" min="0" step="500" value="{{ old('price', (int) $profile->price) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Pencatatan Penjualan <span class="text-danger">*</span></label>
                    <select class="form-select" name="include_in_sales">
                        <option value="1" @selected(old('include_in_sales', $profile->include_in_sales ?? true))>Ya — Masuk ke Data Penjualan (Komersial)</option>
                        <option value="0" @selected(! old('include_in_sales', $profile->include_in_sales ?? true))>Tidak — Jangan Masukkan ke Data Penjualan (Non-Komersial / Free / Internal)</option>
                    </select>
                    <div class="form-text">Jika "Tidak", voucher aktif dari profil ini tidak akan dihitung di Laporan Penjualan/Omset.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Keuntungan Reseller (%)</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="reseller_percent" min="0" max="100" step="0.01" value="{{ old('reseller_percent', (float) $profile->reseller_percent) }}">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text">Isi jika profil ini khusus untuk reseller tertentu.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Kuota Data (MB) — 0 = Unlimited</label>
                    <div class="input-group">
                        <input type="number" class="form-control" name="quota_mb" min="0" value="{{ old('quota_mb', (int) $profile->quota_mb) }}">
                        <span class="input-group-text">MB</span>
                    </div>
                    <div class="form-text">→ Atribut RADIUS: <code>Mikrotik-Total-Limit</code></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rate Upload</label>
                    <input type="text" class="form-control" name="rate_up" value="{{ old('rate_up', $profile->exists ? $profile->rate_up : '10M') }}" placeholder="10M">
                    <div class="form-text">Contoh: <code>512k</code>, <code>2M</code>, <code>10M</code></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Rate Download</label>
                    <input type="text" class="form-control" name="rate_down" value="{{ old('rate_down', $profile->exists ? $profile->rate_down : '10M') }}" placeholder="10M">
                    <div class="form-text">→ <code>Mikrotik-Rate-Limit</code></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Cabang / Lokasi <span class="text-danger">*</span></label>
                    <select class="form-select" name="router_id">
                        <option value="">Semua Cabang (Global)</option>
                        @foreach ($routers as $r)<option value="{{ $r->id }}" @selected((int) old('router_id', $profile->router_id) === $r->id)>{{ $r->name }} ({{ $r->ip_address }})</option>@endforeach
                    </select>
                    <div class="form-text">Profil ini akan masuk ke cabang mana saat penagihan?</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="is_active">
                        <option value="1" @selected(old('is_active', $profile->is_active ?? true))>Aktif</option>
                        <option value="0" @selected(! old('is_active', $profile->is_active ?? true))>Nonaktif</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Keterangan</label>
                    <textarea class="form-control" name="description" rows="2">{{ old('description', $profile->description) }}</textarea>
                </div>
            </div>

            @if ($profile->exists)
            <div class="mt-3 p-3 rounded border" style="background: rgba(13, 110, 253, 0.05); border-color: rgba(13, 110, 253, 0.25) !important;">
                <div class="fw-bold text-primary mb-2 d-flex align-items-center gap-2" style="font-size: 0.9rem;"><i class="bi bi-arrow-repeat"></i> Sinkronisasi ke Voucher yang Ada</div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="sync_existing_vouchers" id="syncExistingVouchers" value="1" checked>
                    <label class="form-check-label fw-semibold" for="syncExistingVouchers" style="font-size: 0.85rem;">Terapkan perubahan limit & durasi ke semua voucher yang belum dipakai</label>
                    <div class="form-text text-muted" style="font-size: 0.75rem;">Memperbarui batas kecepatan (<code>Mikrotik-Rate-Limit</code>) dan durasi/kuota di RADIUS.</div>
                </div>
            </div>
            @endif

            <div class="mt-3 p-3 rounded" style="background:var(--blue-pale); border-left:3px solid var(--blue);">
                <div class="fw-600 mb-2" style="font-size:.8rem;"><i class="bi bi-code me-1"></i>Preview Atribut RADIUS yang akan dikirim:</div>
                <code style="font-size:.78rem;">
                    Session-Timeout = <em>[durasi dalam detik]</em><br>
                    Mikrotik-Rate-Limit = "<em>[upload]</em>/<em>[download]</em>"<br>
                    Mikrotik-Total-Limit = <em>[quota_mb × 1048576 bytes]</em>
                </code>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>{{ $profile->exists ? 'Simpan Perubahan' : 'Tambah Profil' }}</button>
                <a href="{{ route('hotspot-profiles.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
