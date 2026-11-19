@extends('layouts.app')
@section('title', 'Pengaturan Server WireGuard')
@section('page-header')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="{{ route('wireguard.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router</a>
        <h1 class="page-title mt-1"><i class="bi bi-gear-fill me-2 text-primary"></i>Pengaturan Server WireGuard VPN</h1>
        <p class="page-subtitle mb-0">Konfigurasi Endpoint Publik, Subnet Tunnel, dan Kunci Server</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        @if ($isWgRunning)
            <span class="badge bg-success p-2"><i class="bi bi-shield-check me-1"></i> Service wg-quick@{{ $settings['wg_interface'] }} Active</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="restartWireguardService(this)" title="Restart Service WireGuard"><i class="bi bi-arrow-clockwise me-1"></i> Restart Service</button>
        @elseif ($isWgInstalled)
            <span class="badge bg-danger p-2"><i class="bi bi-exclamation-triangle-fill me-1"></i> Service Mati ({{ $serviceStatus }})</span>
            <button type="button" class="btn btn-sm btn-danger fw-bold" onclick="restartWireguardService(this)"><i class="bi bi-play-circle-fill me-1"></i> Nyalakan Service Sekarang</button>
        @else
            <span class="badge bg-warning text-dark p-2"><i class="bi bi-question-circle me-1"></i> Paket WireGuard Belum Terpasang di VPS</span>
        @endif
    </div>
</div>
@endsection
@section('content')
@include('wireguard._tabs')
@include('partials.errors')
<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3"><h6 class="fw-bold mb-0"><i class="bi bi-sliders text-primary me-2"></i>Konfigurasi Server WireGuard</h6></div>
            <div class="card-body p-4">
                <form method="POST" action="{{ route('wireguard.settings') }}">
                    @csrf @method('PUT')
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Server Endpoint WireGuard (IP Publik / Domain:Port) <span class="text-danger">*</span></label>
                            <input type="text" name="wg_server_endpoint" class="form-control font-monospace" value="{{ old('wg_server_endpoint', $settings['wg_server_endpoint']) }}" placeholder="Contoh: vpn.domain.com:51820 atau 203.0.113.1:51820" required>
                            <div class="form-text">Alamat yang digunakan router client MikroTik untuk terhubung ke VPS ini.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Listen Port UDP</label>
                            <input type="number" name="wg_listen_port" class="form-control font-monospace" value="{{ old('wg_listen_port', $settings['wg_listen_port']) }}" placeholder="51820" required>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold"><i class="bi bi-globe2 text-primary me-1"></i> Host / IP Publik / Domain Khusus Remote</label>
                            <input type="text" name="wg_remote_public_host" class="form-control font-monospace" value="{{ old('wg_remote_public_host', $settings['wg_remote_public_host']) }}" placeholder="Contoh: 203.0.113.10 atau direct.domain.com">
                            <div class="form-text text-muted">IP Publik VPS atau Domain Tanpa Proxy jika web menggunakan Cloudflare Tunnel.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold"><i class="bi bi-shuffle text-primary me-1"></i> Rentang Port Acak Remote ONT</label>
                            <input type="text" name="wg_remote_port_range" class="form-control font-monospace" value="{{ old('wg_remote_port_range', $settings['wg_remote_port_range']) }}" placeholder="20000-58000">
                            <div class="form-text text-muted">Buka rentang port ini di firewall aaPanel / Cloud VPS.</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-7">
                            <label class="form-label fw-semibold">Subnet Tunnel VPN (CIDR) <span class="text-danger">*</span></label>
                            <input type="text" name="wg_subnet_prefix" id="wg_subnet_prefix" class="form-control font-monospace" value="{{ old('wg_subnet_prefix', rtrim($settings['wg_subnet_prefix'], '.').'.0/24') }}" placeholder="10.66.66.0/24" required>
                            <div class="d-flex flex-wrap gap-1 mt-2">
                                <span class="small text-muted me-1">Preset:</span>
                                @foreach (['10.66.66.0/24', '10.10.10.0/24', '172.16.0.0/24', '192.168.99.0/24'] as $cidr)<button type="button" class="btn btn-xs btn-outline-secondary py-0" onclick="setSubnet('{{ $cidr }}')">{{ $cidr }}</button>@endforeach
                            </div>
                            <div class="form-text mt-1">IP Server: <code id="server_ip_preview">{{ rtrim($settings['wg_subnet_prefix'], '.') }}.1/24</code> &middot; Client: <code id="client_ip_preview">{{ rtrim($settings['wg_subnet_prefix'], '.') }}.2 s/d .254</code></div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">Nama Interface WireGuard</label>
                            <input type="text" name="wg_interface" class="form-control font-monospace" value="{{ old('wg_interface', $settings['wg_interface']) }}" placeholder="wg0" required>
                            <div class="form-text">Default: <code>wg0</code></div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">DNS Client</label>
                            <input type="text" name="wg_dns" class="form-control font-monospace" value="{{ old('wg_dns', $settings['wg_dns']) }}" placeholder="1.1.1.1, 8.8.8.8">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">MTU Client</label>
                            <input type="number" name="wg_mtu" class="form-control font-monospace" value="{{ old('wg_mtu', $settings['wg_mtu']) }}" placeholder="1420">
                        </div>
                    </div>
                    <hr class="my-4">
                    <h6 class="fw-bold mb-3"><i class="bi bi-key-fill text-warning me-2"></i>Kunci Kriptografi Server (VPS)</h6>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Server Public Key</label>
                        <input type="text" name="wg_server_pubkey" id="server_pubkey" class="form-control font-monospace" value="{{ old('wg_server_pubkey', $settings['wg_server_pubkey']) }}" placeholder="Public Key dari /etc/wireguard/server_public.key">
                        <div class="form-text">Kunci publik ini akan otomatis dimasukkan ke dalam skrip konfigurasi MikroTik.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Server Private Key</label>
                        <input type="password" name="wg_server_privkey" class="form-control font-monospace" autocomplete="new-password" placeholder="{{ filled($settings['wg_server_privkey'] ?? null) ? 'Tersimpan (terenkripsi) — kosongkan jika tidak diubah' : 'Private Key server' }}">
                        <div class="form-text">Disimpan terenkripsi di server untuk keperluan setup interface.</div>
                    </div>
                    <div class="d-flex justify-content-end gap-2">
                        <a href="{{ route('wireguard.index') }}" class="btn btn-light px-4">Batal</a>
                        <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-circle-fill me-1"></i> Simpan Pengaturan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent py-3"><h6 class="fw-bold mb-0"><i class="bi bi-terminal text-dark me-2"></i>Output Live 'wg show'</h6></div>
            <div class="card-body p-0"><pre class="m-0 p-3 bg-dark text-light small font-monospace rounded-bottom" style="max-height: 280px; overflow-y: auto;">{{ $wgShowOutput ?: 'Tidak ada peer yang terhubung / WireGuard belum aktif.' }}</pre></div>
        </div>
        <div class="card border-0 shadow-sm bg-light">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-2"><i class="bi bi-question-circle text-primary me-2"></i>Instalasi Server WireGuard</h6>
                <p class="small text-muted mb-2">Jika server VPS belum memiliki WireGuard, pasang paketnya lalu aktifkan service di terminal VPS:</p>
                <code class="d-block p-2 bg-white border rounded font-monospace small mb-3 text-dark">sudo apt install -y wireguard &amp;&amp; sudo systemctl enable --now wg-quick@{{ $settings['wg_interface'] }}</code>
                <p class="small text-muted mb-0">User web server perlu izin <code>sudo -n wg</code>, <code>wg-quick</code>, <code>iptables</code>, dan <code>systemctl restart wg-quick@*</code> tanpa password.</p>
            </div>
        </div>
        <div class="card border-0 shadow-sm border-start border-success border-4 mt-4">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-2 text-success"><i class="bi bi-arrow-repeat me-2"></i>Sinkronkan Database ke WireGuard</h6>
                <p class="small text-muted mb-3">Jika Anda baru saja menginstal ulang WireGuard atau peer hilang dari daftar kernel Linux, klik tombol di bawah untuk mendaftarkan ulang seluruh router dari database.</p>
                <button type="button" class="btn btn-success w-100" onclick="wgSyncAll(this)"><i class="bi bi-arrow-repeat me-1"></i> Sinkronkan Semua Router Sekarang</button>
            </div>
        </div>
    </div>
</div>
@include('wireguard._tools')
<script>
function setSubnet(cidr) { document.getElementById('wg_subnet_prefix').value = cidr; updateSubnetPreview(); }
function updateSubnetPreview() {
    const val = document.getElementById('wg_subnet_prefix').value.trim().replace(/\/\d+$/, '').replace(/\.0$/, '').replace(/\.*$/, '');
    if (val) {
        document.getElementById('server_ip_preview').innerText = val + '.1/24';
        document.getElementById('client_ip_preview').innerText = val + '.2 s/d .254';
    }
}
document.getElementById('wg_subnet_prefix').addEventListener('input', updateSubnetPreview);
function restartWireguardService(btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Memproses...';
    wgTool('restart_service', {}, true)
        .then(d => alert(d.success ? '✓ Service WireGuard berhasil dijalankan / direstart!\nStatus: ' + d.status
            : 'Perhatian: Gagal merestart service WireGuard otomatis via web.\n\n' + (d.message || 'Status: ' + d.status) + '\n\nSilakan jalankan perintah ini di Terminal SSH VPS:\nsudo systemctl restart wg-quick@' + @json($settings['wg_interface'])))
        .catch(err => alert('Gagal komunikasi dengan server: ' + err.message))
        .finally(() => location.reload());
}
</script>
@endsection
