@extends('layouts.app')
@section('title', 'Backup & Restore')
@section('page-header')
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <h1 class="page-title"><i class="bi bi-cloud-arrow-down me-2 text-primary"></i>Backup &amp; Restore Database</h1>
        <p class="page-subtitle mb-0">Cadangkan seluruh database untuk pindah VPS atau pulihkan data dari file cadangan</p>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas maksimal ukuran file upload per request"><i class="bi bi-cloud-arrow-up me-1"></i>Upload Max: {{ $maxUpload }}</span>
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas maksimal payload POST server"><i class="bi bi-arrow-up-circle me-1"></i>Post Max: {{ $maxPost }}</span>
        <span class="badge bg-light text-secondary border px-2 py-2" title="Batas memori eksekusi PHP"><i class="bi bi-cpu me-1"></i>RAM PHP: {{ $memLimit }}</span>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row g-4">
<div class="col-12 col-lg-7">
<div class="card border-primary shadow-sm h-100">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
        <h5 class="card-title mb-0"><i class="bi bi-box-arrow-in-down me-2"></i>Pulihkan / Restore Database</h5>
        <span class="badge bg-white text-primary fw-bold">Via Terminal (Aman)</span>
    </div>
    <div class="card-body p-4">
        <div class="alert alert-warning d-flex gap-2 align-items-start" style="font-size:.85rem;">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
            <div><strong>Perhatian:</strong> Restore menimpa seluruh data yang ada. Pastikan Anda sudah membuat backup terbaru sebelum melanjutkan. Demi keamanan, restore dijalankan dari terminal VPS — bukan upload lewat browser.</div>
        </div>
        <h6 class="fw-bold"><span class="badge bg-success rounded-circle me-2">1</span>Restore Penuh (Pindah VPS — versi Laravel)</h6>
        <p class="text-muted small mb-2">Salin file <code>.sql.gz</code> dari VPS lama ke VPS baru, lalu jalankan:</p>
        <pre class="bg-dark text-light p-3 rounded small mb-4">gunzip -c snet-YYYYMMDD-HHMMSS.sql.gz | mysql -u USER -p NAMA_DATABASE
cd {{ base_path() }} &amp;&amp; php artisan migrate --force</pre>
        <h6 class="fw-bold"><span class="badge bg-primary rounded-circle me-2">2</span>Migrasi dari S.NET Lama (PHP Native / V1–V2)</h6>
        <p class="text-muted small mb-2">Import database lama ke database terpisah, isi koneksi <code>LEGACY_DB_*</code> di <code>.env</code>, lalu jalankan importer (pelanggan, voucher, router, WireGuard, WhatsApp, pembayaran, dll.):</p>
        <pre class="bg-dark text-light p-3 rounded small mb-3">cd {{ base_path() }}
php artisan snet:import --source=legacy            # semua data
php artisan snet:import --source=legacy --skip-acct  # tanpa riwayat sesi radacct</pre>
        <p class="text-muted small mb-0"><i class="bi bi-arrow-down-circle me-1"></i>Setelah restore/migrasi, jalankan <strong>Sinkronisasi FreeRADIUS</strong> di bawah.</p>
    </div>
</div>
</div>

<div class="col-12 col-lg-5">
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center py-3">
            <h5 class="card-title mb-0"><i class="bi bi-download me-2 text-primary"></i>Backup Database</h5>
            <span class="badge bg-secondary">{{ count($files) }} / {{ \App\Services\Settings\BackupService::KEEP }} file</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-2">Backup otomatis berjalan tiap malam pukul 02:00 (disimpan {{ \App\Services\Settings\BackupService::KEEP }} terakhir). Simpan file backup sebelum mematikan VPS lama.</p>
            <div class="bg-light rounded p-2 mb-3 border" style="max-height:220px;overflow-y:auto;">
                @forelse ($files as $f)
                <div class="d-flex justify-content-between align-items-center py-1 border-bottom gap-2" style="font-size:.75rem;">
                    <span class="font-monospace text-dark text-truncate">{{ $f['name'] }}</span>
                    <span class="text-muted text-nowrap">{{ number_format($f['size'] / 1048576, 2) }} MB · {{ date('d/m H:i', $f['time']) }}</span>
                    <a class="btn btn-sm btn-outline-primary py-0 px-1" href="{{ route('settings.backup.download', $f['name']) }}" title="Download"><i class="bi bi-download"></i></a>
                </div>
                @empty
                <div class="text-muted small text-center py-2">Belum ada file backup.</div>
                @endforelse
            </div>
            <form method="POST" action="{{ route('settings.backup.create') }}" class="d-flex flex-column gap-2">
                @csrf
                <button type="submit" class="btn btn-success btn-lg shadow-sm d-flex justify-content-between align-items-center" title="Format terkompresi, hemat ukuran hingga 90%">
                    <span><i class="bi bi-file-earmark-zip me-2"></i>Buat Backup Sekarang</span>
                    <span class="badge bg-white text-success small">.SQL.GZ</span>
                </button>
            </form>
        </div>
    </div>
    <div class="card shadow-sm border-info">
        <div class="card-header bg-info bg-opacity-10 text-dark py-2"><h6 class="card-title mb-0 fw-bold"><i class="bi bi-signpost-2-fill me-1 text-primary"></i>Alur Pindah VPS</h6></div>
        <div class="card-body" style="font-size:.83rem;">
            <ol class="ps-3 mb-2 text-muted">
                <li class="mb-1"><strong>Di VPS Lama:</strong> Klik <strong>Buat Backup Sekarang</strong> lalu download file <code>.sql.gz</code>.</li>
                <li class="mb-1"><strong>Di VPS Baru:</strong> Pasang aplikasi S.NET Manager (Laravel) dan buat database kosong.</li>
                <li class="mb-1"><strong>Di VPS Baru:</strong> Jalankan perintah restore (langkah 1) di kartu sebelah kiri.</li>
                <li class="mb-1"><strong>Selesai!</strong> Jalankan Sinkronisasi FreeRADIUS agar voucher, PPPoE &amp; router aktif kembali.</li>
            </ol>
            <div class="alert alert-light border py-2 px-3 mb-0 small text-dark"><i class="bi bi-lightbulb-fill text-warning me-1"></i><strong>Tips:</strong> Salin juga file <code>.env</code> (APP_KEY wajib sama agar kata sandi terenkripsi bisa dibaca) dan folder sesi WhatsApp agar tidak perlu scan QR ulang.</div>
        </div>
    </div>
</div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card border-warning shadow-sm" id="sinkronisasi-radius">
            <div class="card-header bg-warning bg-opacity-25 d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
                <h5 class="card-title mb-0 text-dark fw-bold"><i class="bi bi-arrow-repeat me-2 text-warning"></i>Sinkronisasi FreeRADIUS &amp; MikroTik</h5>
                <div class="d-flex align-items-center gap-2">
                    @if ($radStatus['is_active'])<span class="badge bg-success px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>FreeRADIUS: AKTIF</span>
                    @else<span class="badge bg-danger px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>FreeRADIUS: NONAKTIF</span>@endif
                    <span class="badge bg-warning text-dark"><i class="bi bi-shield-check me-1"></i>Menu Wajib Pasca Migrasi</span>
                </div>
            </div>
            <div class="card-body p-4">
                @unless ($radStatus['is_active'])
                <div class="alert alert-danger d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
                    <i class="bi bi-exclamation-octagon-fill fs-5 mt-1 flex-shrink-0"></i>
                    <div><strong>Service FreeRADIUS Terdeteksi Nonaktif!</strong><br>Anda dapat menyinkronkan data database di bawah terlebih dahulu, lalu hidupkan kembali FreeRADIUS melalui menu <a href="{{ route('settings.general') }}" class="fw-bold">Pengaturan</a>.</div>
                </div>
                @endunless
                <div class="alert alert-info d-flex gap-2 align-items-start mb-3" style="font-size:.85rem;">
                    <i class="bi bi-info-circle-fill fs-5 mt-1 flex-shrink-0 text-primary"></i>
                    <div><strong>Kenapa Router / RADIUS tidak mau konek setelah migrasi?</strong><br>Setelah import data, mesin FreeRADIUS membutuhkan sinkronisasi ulang agar seluruh daftar voucher hotspot, pelanggan PPPoE, dan IP Router (tabel <code>nas</code>) terdaftar resmi di layanan RADIUS server.</div>
                </div>
                <div class="row g-2 mb-4 text-center">
                    <div class="col-6 col-md-3"><div class="p-3 border rounded bg-light"><div class="fw-bold fs-4 text-primary">{{ number_format($statVouchers) }}</div><div class="text-muted small">Voucher Siap Sync</div></div></div>
                    <div class="col-6 col-md-3"><div class="p-3 border rounded bg-light"><div class="fw-bold fs-4 text-success">{{ number_format($statPppoe) }}</div><div class="text-muted small">PPPoE Siap Sync</div></div></div>
                    <div class="col-6 col-md-3"><div class="p-3 border rounded bg-light"><div class="fw-bold fs-4 text-danger">{{ number_format($statRouters) }}</div><div class="text-muted small">Router NAS Aktif</div></div></div>
                    <div class="col-6 col-md-3"><div class="p-3 border rounded bg-light"><div class="fw-bold fs-4 text-dark">{{ number_format($statRadcheck) }}</div><div class="text-muted small">Akun Terdaftar di FreeRADIUS</div></div></div>
                </div>
                <div class="row g-3 mb-4">
                    <div class="col-md-4"><div class="border rounded p-3 h-100 bg-white"><h6 class="fw-bold text-primary mb-1"><i class="bi bi-ticket-perforated me-1"></i>1. Hotspot Vouchers</h6><p class="text-muted small mb-0">Menulis ulang akun voucher belum terpakai ke tabel <code>radcheck</code> dan <code>radreply</code> (password, rate limit, kuota, session timeout).</p></div></div>
                    <div class="col-md-4"><div class="border rounded p-3 h-100 bg-white"><h6 class="fw-bold text-success mb-1"><i class="bi bi-house-door me-1"></i>2. PPPoE Rumahan</h6><p class="text-muted small mb-0">Menyinkronkan seluruh akun PPPoE Rumahan ke <code>radcheck</code>, <code>radreply</code>, dan <code>radusergroup</code> tanpa menghapus data pelanggan.</p></div></div>
                    <div class="col-md-4"><div class="border rounded p-3 h-100 bg-white"><h6 class="fw-bold text-danger mb-1"><i class="bi bi-hdd-network me-1"></i>3. Router &amp; NAS Secret</h6><p class="text-muted small mb-0">Mendaftarkan IP router dan RADIUS secret ke tabel <code>nas</code> FreeRADIUS agar MikroTik diizinkan berkomunikasi dengan FreeRADIUS.</p></div></div>
                </div>
                <form method="POST" action="{{ route('routers.sync-radius') }}" onsubmit="this.querySelector('button').disabled = true;">
                    @csrf
                    <button type="submit" class="btn btn-warning btn-lg w-100 text-dark fw-bold shadow-sm"><i class="bi bi-arrow-repeat me-2"></i>Mulai Sinkronisasi FreeRADIUS Sekarang</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
