@extends('layouts.app')
@section('title', $router->exists ? 'Edit Router WireGuard — '.$router->name : 'Tambah Router WireGuard')
@section('page-header')
<div class="page-header mb-4">
    @if ($router->exists)
    <a href="{{ route('wireguard.show', $router) }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Kembali ke Detail Router</a>
    <h1 class="page-title mt-1"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Router: {{ $router->name }}</h1>
    <p class="page-subtitle mb-0">Ubah konfigurasi IP, subnet LAN, dan kunci WireGuard</p>
    @else
    <a href="{{ route('wireguard.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router</a>
    <h1 class="page-title mt-1"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>Tambah Router WireGuard Baru</h1>
    <p class="page-subtitle mb-0">Hubungkan router MikroTik cabang atau client ke Server VPN S.NET</p>
    @endif
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="{{ $router->exists ? route('wireguard.update', $router) : route('wireguard.store') }}">
                    @csrf @if ($router->exists) @method('PUT') @endif
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nama Router / Lokasi <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="{{ old('name', $router->name) }}" placeholder="Contoh: Router-Cabang-Sentani / MikroTik-RB450Gx4" required @unless ($router->exists) autofocus @endunless>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Lokasi / Cabang</label>
                            <input type="text" name="location" class="form-control" value="{{ old('location', $router->location) }}" placeholder="Contoh: Kantor Cabang Jayapura">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">IP Tunnel VPN <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" name="tunnel_ip" id="tunnel_ip" class="form-control font-monospace" value="{{ old('tunnel_ip', $router->tunnel_ip) }}" required>
                                @unless ($router->exists)<button type="button" class="btn btn-outline-secondary" onclick="wgTool('get_next_ip').then(d => d.ip && (document.getElementById('tunnel_ip').value = d.ip))" title="Cari IP Baru"><i class="bi bi-arrow-clockwise"></i></button>@endunless
                            </div>
                            <div class="form-text">Subnet: <code>{{ $settings['wg_subnet_prefix'] }}0/24</code></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Listen Port MikroTik</label>
                            <input type="number" name="listen_port" class="form-control font-monospace" value="{{ old('listen_port', $router->listen_port ?? 13231) }}" placeholder="13231" required>
                            <div class="form-text">Port unik interface di MikroTik client.</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Subnet LAN Router (Opsional — untuk Interkoneksi Site-to-Site)</label>
                        <input type="text" name="lan_subnets" class="form-control font-monospace" value="{{ old('lan_subnets', $router->lan_subnets) }}" placeholder="Contoh: 192.168.10.0/24, 192.168.20.0/24">
                        <div class="form-text">Pisahkan dengan koma jika router memiliki lebih dari satu subnet lokal.</div>
                    </div>
                    <hr class="my-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Kunci Kriptografi WireGuard</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Public Key Client</label>
                            <div class="input-group">
                                <input type="text" id="public_key" class="form-control font-monospace" value="{{ $router->public_key }}" readonly>
                                <button type="button" class="btn btn-outline-primary" onclick="wgTool('generate_keys').then(d => { document.getElementById('public_key').value = d.public_key; document.getElementById('private_key').value = d.private_key; })"><i class="bi bi-shuffle me-1"></i> Generate Kunci Baru</button>
                            </div>
                            <div class="form-text">Diturunkan otomatis dari private key.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Private Key Client <span class="text-danger">*</span></label>
                            <input type="text" name="private_key" id="private_key" class="form-control font-monospace" value="{{ old('private_key', $router->private_key) }}" required>
                            <div class="form-text">Private key ini digunakan pada router client untuk membuka koneksi ke server.</div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Catatan Tambahan</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Catatan teknis, kontak PIC cabang, dll.">{{ old('notes', $router->notes) }}</textarea>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ $router->exists ? route('wireguard.show', $router) : route('wireguard.index') }}" class="btn btn-light px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-circle-fill me-1"></i> {{ $router->exists ? 'Perbarui Router' : 'Simpan & Buat Skrip MikroTik' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @unless ($router->exists)
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm bg-primary-subtle border-primary mb-4">
            <div class="card-body p-4">
                <h6 class="fw-bold text-primary mb-2"><i class="bi bi-info-circle-fill me-2"></i>Panduan Singkat</h6>
                <p class="small text-muted mb-2">Setelah menyimpan form ini:</p>
                <ol class="small text-muted ps-3 mb-0" style="line-height: 1.6;">
                    <li>Sistem otomatis mendaftarkan peer ke service WireGuard Linux.</li>
                    <li>Sistem akan men-generate skrip RouterOS v7 MikroTik 1-klik siap pakai.</li>
                    <li>Anda cukup copy skrip tersebut dan paste di Terminal Winbox router target.</li>
                    <li>Router langsung terhubung ke VPS secara instan.</li>
                </ol>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="bi bi-server me-2"></i>Info Server WireGuard</h6>
                <div class="small mb-2"><span class="text-muted d-block">Endpoint Server:</span><code class="fw-bold text-dark">{{ $settings['wg_server_endpoint'] }}</code></div>
                <div class="small mb-2"><span class="text-muted d-block">Server Public Key:</span><code class="d-block text-truncate text-dark" style="max-width: 250px;">{{ $settings['wg_server_pubkey'] ?: '(Belum diatur)' }}</code></div>
                <div class="small"><span class="text-muted d-block">Subnet Tunnel:</span><code>{{ $settings['wg_subnet_prefix'] }}0/24</code></div>
            </div>
        </div>
    </div>
    @endunless
</div>
@include('wireguard._tools')
@endsection
