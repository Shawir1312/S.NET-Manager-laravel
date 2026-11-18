@extends('layouts.app')
@section('title', 'Pengaturan PPPoE & Billing')
@php
    $v = fn ($k, $d = '') => old($k, $s[$k] ?? $d);
    $secretSet = fn ($k) => filled($s[$k] ?? null);
    $host = request()->getHost();
@endphp
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-gear-wide-connected text-primary me-2"></i>Pengaturan PPPoE &amp; Billing</h1>
        <p class="page-subtitle">Kelola template provisioning ONT, konfigurasi isolir otomatis, dan payment gateway Midtrans</p>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<form method="POST" action="{{ route('pppoe-settings.update') }}" autocomplete="off">
    @csrf @method('PUT')
    <div class="row g-4">
        {{-- CARD 1: TEMPLATE AUTO-PROVISIONING ONT --}}
        <div class="col-12">
            <div class="card border-0 shadow-sm border-start border-4 border-success">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-success mb-0 fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i>⚡ Template &amp; Preset Auto-Provisioning ONT (GenieACS TR-069)</h5>
                    <span class="badge bg-success">Zero-Touch Automation</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3" style="font-size: .88rem;">Konfigurasi format otomatis username PPPoE, nama Wi-Fi, dan template ONT saat menambah pelanggan baru.</p>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Suffix / Akhiran Username PPPoE</label>
                            <input type="text" class="form-control font-mono fw-bold text-primary" name="ont_username_suffix" value="{{ $v('ont_username_suffix', '@snet') }}" placeholder="@snet">
                            <div class="form-text">Contoh: <code>@snet</code> &rarr; username: <code>nama@snet</code></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Format Nama Wi-Fi (SSID 2.4 &amp; 5 GHz)</label>
                            <input type="text" class="form-control" name="ont_wifi1_prefix" value="{{ $v('ont_wifi1_prefix', 'S.NET - ') }}" placeholder="S.NET - ">
                            <div class="form-text">Contoh: <code>S.NET - </code> &rarr; SSID: <code>S.NET - Budi</code></div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <a href="{{ route('templates.index') }}" class="btn btn-outline-success w-100"><i class="bi bi-file-earmark-text me-1"></i> Kelola Template ONT</a>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Template ONT Default — PPPoE</label>
                            <select class="form-select" name="default_pppoe_template_id">
                                @foreach ($templates->where('service_type', \App\Enums\ServiceType::Pppoe) as $t)<option value="{{ $t->id }}" @selected($t->is_default)>{{ $t->name }}</option>@endforeach
                            </select>
                            <div class="form-text">Slot WAN, VLAN, binding LAN/SSID diatur di template (mesin ONT otomatis memilih slot per merek/model).</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Template ONT Default — Hotspot (WAN 2 Bridge)</label>
                            <select class="form-select" name="default_hotspot_template_id">
                                @foreach ($templates->where('service_type', \App\Enums\ServiceType::Bridge) as $t)<option value="{{ $t->id }}" @selected($t->is_default)>{{ $t->name }}</option>@endforeach
                            </select>
                            <div class="form-text">VLAN hotspot dan nama SSID 2/6 (mis. <code>S.NET @Hotspot</code>) diisi di template Hotspot.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- CARD 2: PENGATURAN ISOLIR --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white py-3"><h5 class="card-title text-danger mb-0 fw-bold"><i class="bi bi-shield-slash me-2"></i>Konfigurasi Auto-Isolir</h5></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Nama Profil Isolir di MikroTik <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="isolir_profile" required value="{{ $v('isolir_profile', 'isolir') }}" placeholder="isolir">
                        <div class="form-text">Nama profile PPP di MikroTik yang digunakan untuk mengarahkan pelanggan menunggak ke web isolir.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Masa Tenggang / Grace Period (Hari) <span class="text-danger">*</span></label>
                        <div class="input-group"><input type="number" class="form-control" name="isolir_grace_days" required min="0" max="31" value="{{ $v('isolir_grace_days', 3) }}"><span class="input-group-text">Hari</span></div>
                        <div class="form-text">Jumlah hari toleransi setelah tanggal jatuh tempo sebelum isolir otomatis.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">URL Halaman Isolir Pelanggan</label>
                        <input type="text" class="form-control" name="isolir_redirect_url" value="{{ $v('isolir_redirect_url', route('isolir')) }}" placeholder="{{ route('isolir') }}">
                        <div class="form-text">Halaman tujuan di mana pelanggan terisolir dapat melihat rincian tagihan &amp; bayar online.</div>
                    </div>
                    <div class="p-3 bg-danger-subtle border border-danger-subtle rounded mt-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-danger"><i class="bi bi-terminal-fill me-1"></i> Script MikroTik Auto-Redirect</div>
                                <div class="small text-muted">Generate otomatis IP Pool, Profile, Web Proxy &amp; Firewall NAT/Filter</div>
                            </div>
                            <button type="button" class="btn btn-danger btn-sm fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalScriptIsolir"><i class="bi bi-code-square me-1"></i> Buka Script MikroTik</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- CARD 3: IDENTITAS LAYANAN --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-header bg-white py-3"><h5 class="card-title text-primary mb-0 fw-bold"><i class="bi bi-building me-2"></i>Kontak &amp; Identitas Layanan</h5></div>
                <div class="card-body">
                    <div class="mb-3"><label class="form-label fw-bold">Nama Layanan / Perusahaan</label><input type="text" class="form-control" name="company_name" value="{{ $v('company_name', 'S.NET Internet') }}" placeholder="S.NET Internet"></div>
                    <div class="mb-3"><label class="form-label fw-bold">Nomor WhatsApp / CS Bantuan</label><input type="text" class="form-control" name="company_phone" value="{{ $v('company_phone') }}" placeholder="081234567890">
                        <div class="form-text">Ditampilkan di kwitansi, invoice WhatsApp, dan portal isolir pelanggan.</div></div>
                    <div class="mb-3"><label class="form-label fw-bold">Alamat Kantor / Keterangan</label><textarea class="form-control" name="company_address" rows="3" placeholder="Alamat kantor layanan...">{{ $v('company_address') }}</textarea></div>
                </div>
            </div>
        </div>

        {{-- CARD 4: MULTI-PAYMENT GATEWAY --}}
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-success mb-0 fw-bold"><i class="bi bi-wallet2 me-2"></i>Multi-Payment Gateway Settings</h5>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Otomatisasi Pembayaran Online</span>
                </div>
                <div class="card-body">
                    <label class="form-label fw-bold">Pilihan Gateway Aktif untuk Pelanggan <span class="text-danger">*</span></label>
                    <select class="form-select form-select-lg fw-semibold" name="active_payment_gateway">
                        @foreach (['both' => '⚡ Multi-Gateway (Midtrans & Tripay Keduanya Aktif — Pelanggan Dapat Memilih)', 'midtrans' => '💳 Hanya Midtrans Snap (QRIS, GoPay, ShopeePay, VA Bank)', 'tripay' => '🚀 Hanya Tripay Gateway (QRIS Realtime, VA Semua Bank, Alfamart/Indomaret)', 'none' => '⛔ Nonaktif (bayar di kasir saja)'] as $k => $l)
                            <option value="{{ $k }}" @selected($v('active_payment_gateway', 'none') === $k)>{{ $l }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">Pilih apakah pelanggan dapat memilih antara Midtrans &amp; Tripay secara fleksibel, atau hanya menggunakan satu gateway utama.</div>
                </div>
            </div>
        </div>

        {{-- CARD 4B: MIDTRANS --}}
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-primary mb-0 fw-bold"><i class="bi bi-credit-card me-2"></i>Gateway 1: Midtrans Snap</h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">Snap Pop-up &amp; Redirect</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label fw-bold">Midtrans Mode <span class="text-danger">*</span></label>
                            <select class="form-select" name="midtrans_mode"><option value="sandbox" @selected($v('midtrans_mode', 'sandbox') === 'sandbox')>Sandbox (Mode Testing)</option><option value="production" @selected($v('midtrans_mode') === 'production')>Production (Live Transaksi Nyata)</option></select>
                            <div class="form-text">Gunakan <b>Sandbox</b> untuk uji coba dan <b>Production</b> untuk live transaksi.</div></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Midtrans Client Key</label><input type="text" class="form-control font-mono" name="midtrans_client_key" value="{{ $v('midtrans_client_key') }}" placeholder="SB-Mid-client-...">
                            <div class="form-text">Client Key dari dashboard Midtrans (Snap JS).</div></div>
                        <div class="col-md-4"><label class="form-label fw-bold">Midtrans Server Key</label><input type="password" class="form-control font-mono" name="midtrans_server_key" placeholder="{{ $secretSet('midtrans_server_key') ? '•••••• tersimpan (kosong = tetap)' : 'SB-Mid-server-...' }}" autocomplete="new-password">
                            <div class="form-text">Server Key untuk otentikasi request token &amp; signature (disimpan terenkripsi).</div></div>
                    </div>
                    <div class="alert alert-info mt-4 mb-0 d-flex gap-3 align-items-start">
                        <i class="bi bi-info-circle-fill fs-5 mt-1 text-primary"></i>
                        <div style="font-size: .88rem;"><strong>Webhook / Notification URL Midtrans:</strong><br>Salin URL berikut ke menu <em>Settings &gt; Configuration &gt; Payment Notification URL</em> di Dashboard Midtrans Anda:
                            <code class="d-block mt-1 p-2 bg-light border rounded text-dark">{{ route('webhooks.payment') }}</code></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- CARD 4C: TRIPAY --}}
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title text-warning mb-0 fw-bold"><i class="bi bi-lightning-charge-fill me-2"></i>Gateway 2: Tripay Payment Gateway</h5>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">QRIS &amp; Direct Virtual Account</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label fw-bold">Tripay Mode <span class="text-danger">*</span></label>
                            <select class="form-select" name="tripay_mode"><option value="sandbox" @selected($v('tripay_mode', 'sandbox') === 'sandbox')>Sandbox (Mode Testing)</option><option value="production" @selected($v('tripay_mode') === 'production')>Production (Live Transaksi Nyata)</option></select>
                            <div class="form-text">Mode Sandbox untuk simulasi, Production untuk transaksi asli.</div></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Kode Merchant (Merchant Code)</label><input type="text" class="form-control font-mono" name="tripay_merchant_code" value="{{ $v('tripay_merchant_code') }}" placeholder="Contoh: T12345">
                            <div class="form-text">Didapatkan dari dashboard Tripay (contoh: TXXXXX).</div></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Tripay API Key</label><input type="password" class="form-control font-mono" name="tripay_api_key" placeholder="{{ $secretSet('tripay_api_key') ? '•••••• tersimpan (kosong = tetap)' : 'DEV-...' }}" autocomplete="new-password">
                            <div class="form-text">API Key dari menu Pengaturan Akun &gt; Integrasi API.</div></div>
                        <div class="col-md-3"><label class="form-label fw-bold">Tripay Private Key</label><input type="password" class="form-control font-mono" name="tripay_private_key" placeholder="{{ $secretSet('tripay_private_key') ? '•••••• tersimpan (kosong = tetap)' : 'Private Key rahasia...' }}" autocomplete="new-password">
                            <div class="form-text">Private Key rahasia untuk tanda tangan HMAC signature.</div></div>
                    </div>
                    <div class="alert alert-warning mt-4 mb-0 d-flex gap-3 align-items-start">
                        <i class="bi bi-shield-lock-fill fs-5 mt-1 text-warning"></i>
                        <div style="font-size: .88rem;"><strong>Webhook / Callback URL Tripay:</strong><br>Salin URL berikut ke menu <em>Merchant &gt; Kelola &gt; Integrasi API &gt; Callback URL</em> di Dashboard Tripay Anda:
                            <code class="d-block mt-1 p-2 bg-light border rounded text-dark">{{ route('webhooks.payment') }}</code>
                            <small class="text-muted mt-1 d-block">Sistem akan secara instan membuka isolir pelanggan dan mengirim kwitansi WhatsApp begitu pembayaran Tripay diterima.</small></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- CARD 5: CRON --}}
        <div class="col-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3"><h5 class="card-title text-warning mb-0 fw-bold"><i class="bi bi-clock-history me-2"></i>Jadwal Cron Auto-Isolir</h5></div>
                <div class="card-body">
                    <p class="text-muted mb-2" style="font-size:.88rem;">Cukup satu baris cron untuk semua tugas otomatis (isolir tiap jam, pengingat WA jam 08:00, voucher, backup, sinkron ONT):</p>
                    <div class="p-3 bg-dark text-light rounded font-mono" style="font-size:.82rem;">* * * * * cd {{ base_path() }} &amp;&amp; php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</div>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-4 mb-5 d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-lg px-4 shadow"><i class="bi bi-save me-1"></i> Simpan Pengaturan</button>
        <a href="{{ route('customers.index') }}" class="btn btn-outline-secondary btn-lg px-4">Kembali ke Pelanggan</a>
    </div>
</form>

<div class="modal fade" id="modalScriptIsolir" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-terminal-fill me-2"></i>Panduan &amp; Script MikroTik Auto-Redirect Isolir PPPoE</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="alert alert-warning border-warning d-flex gap-3 align-items-start">
                    <i class="bi bi-exclamation-triangle-fill fs-4 text-warning mt-1"></i>
                    <div>
                        <div class="fw-bold">Mengapa Pelanggan Isolir Sering Tidak Mau Redirect Otomatis?</div>
                        <div style="font-size: .88rem;" class="mt-1">
                            1. <b>Masalah HTTPS (Port 443):</b> MikroTik <u>tidak bisa</u> me-redirect port 443 tanpa memicu error sertifikat SSL di browser.<br>
                            2. <b>Solusi:</b>
                            <ul>
                                <li>Redirect traffic <b>HTTP (Port 80)</b> ke Web Proxy MikroTik yang mengarahkan browser ke halaman portal isolir.</li>
                                <li><b>REJECT</b> traffic <b>HTTPS (Port 443)</b> dengan <code>tcp-reset</code> agar HP/laptop memunculkan pop-up <i>"Masuk ke Jaringan Wi-Fi"</i>.</li>
                                <li>Whitelist domain billing &amp; payment gateway agar halaman bayar dapat dibuka.</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white py-2 fw-bold"><i class="bi bi-sliders me-1 text-primary"></i> Parameter Konfigurasi MikroTik</div>
                    <div class="card-body"><div class="row g-3">
                        <div class="col-md-4"><label class="form-label small fw-bold">Domain / Host Server Billing</label><input type="text" id="gen_host" class="form-control form-control-sm font-mono" value="{{ $host }}" oninput="generateScript()"></div>
                        <div class="col-md-3"><label class="form-label small fw-bold">Nama PPP Profile Isolir</label><input type="text" id="gen_profile" class="form-control form-control-sm font-mono" value="{{ $v('isolir_profile', 'isolir') }}" oninput="generateScript()"></div>
                        <div class="col-md-3"><label class="form-label small fw-bold">Subnet Gateway Isolir</label><input type="text" id="gen_gw" class="form-control form-control-sm font-mono" value="10.10.99.1" oninput="generateScript()"></div>
                        <div class="col-md-2"><label class="form-label small fw-bold">Pool IP Range</label><input type="text" id="gen_pool" class="form-control form-control-sm font-mono" value="10.10.99.2-10.10.99.254" oninput="generateScript()"></div>
                    </div></div>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-bold text-dark"><i class="bi bi-file-code-fill text-danger me-1"></i> Salin Script berikut ke Terminal MikroTik (New Terminal Winbox):</div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyScript()"><i class="bi bi-clipboard me-1"></i> Salin Semua Script</button>
                </div>
                <textarea id="mikrotikScriptArea" class="form-control font-mono bg-dark text-success p-3 rounded" rows="18" readonly style="font-size: .84rem; line-height: 1.5;"></textarea>
                <div class="mt-3 small text-muted"><b>Catatan Penting:</b> Saat admin mengisolir pelanggan atau isolir otomatis berjalan, sistem mengubah profil dan memutus sesi pelanggan sehingga modem dial ulang dan mendapatkan IP isolir.</div>
            </div>
            <div class="modal-footer bg-white py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-primary" onclick="copyScript()"><i class="bi bi-clipboard me-1"></i> Salin Script ke Clipboard</button>
            </div>
        </div>
    </div>
</div>
@endsection
@push('scripts')
<script>
const isolirPath = @json(parse_url(route('isolir'), PHP_URL_PATH));
function generateScript() {
    const host = document.getElementById('gen_host').value.trim() || location.host;
    const profile = document.getElementById('gen_profile').value.trim() || 'isolir';
    const gw = document.getElementById('gen_gw').value.trim() || '10.10.99.1';
    const pool = document.getElementById('gen_pool').value.trim() || '10.10.99.2-10.10.99.254';
    document.getElementById('mikrotikScriptArea').value = `# ====================================================================
# S.NET MANAGER - SCRIPT OTOMATISASI REDIRECT ISOLIR PPPOE MIKROTIK
# Buka New Terminal di Winbox, lalu Paste script ini secara keseluruhan.
# ====================================================================
/ip pool add name="pool-${profile}" ranges=${pool}
/ppp profile add name="${profile}" local-address=${gw} remote-address="pool-${profile}" dns-server=${gw} rate-limit="256k/256k" address-list="ISOLIR" comment="Profile Isolir S.NET"
/ip proxy set enabled=yes port=8080 max-cache-size=none
/ip proxy access add action=deny redirect-to="http://${host}${isolirPath}" comment="Redirect Pelanggan ke Portal Isolir"
/ip firewall address-list add list="WHITELIST-ISOLIR" address="${host}" comment="Domain Server Billing S.NET"
/ip firewall address-list add list="WHITELIST-ISOLIR" address="app.midtrans.com" comment="Midtrans Snap Payment"
/ip firewall address-list add list="WHITELIST-ISOLIR" address="api.midtrans.com" comment="Midtrans API"
/ip firewall address-list add list="WHITELIST-ISOLIR" address="tripay.co.id" comment="Tripay Website & API"
/ip firewall address-list add list="WHITELIST-ISOLIR" address="payment.tripay.co.id" comment="Tripay Payment Server"
/ip firewall filter add chain=forward src-address-list="ISOLIR" protocol=udp dst-port=53 action=accept place-before=0 comment="ISOLIR: Allow DNS UDP"
/ip firewall filter add chain=forward src-address-list="ISOLIR" protocol=tcp dst-port=53 action=accept place-before=1 comment="ISOLIR: Allow DNS TCP"
/ip firewall filter add chain=forward src-address-list="ISOLIR" dst-address-list="WHITELIST-ISOLIR" action=accept place-before=2 comment="ISOLIR: Allow Billing, Midtrans & Tripay"
/ip firewall filter add chain=forward src-address-list="ISOLIR" protocol=tcp dst-port=443 action=reject reject-with=tcp-reset place-before=3 comment="ISOLIR: Reject HTTPS for Captive Popup"
/ip firewall filter add chain=forward src-address-list="ISOLIR" action=drop place-before=4 comment="ISOLIR: Drop Other Internet Traffic"
/ip firewall nat add chain=dstnat src-address-list="ISOLIR" dst-address-list=!"WHITELIST-ISOLIR" protocol=tcp dst-port=80 action=redirect to-ports=8080 place-before=0 comment="ISOLIR: Redirect HTTP to Web Proxy"
`;
}
function copyScript() {
    navigator.clipboard.writeText(document.getElementById('mikrotikScriptArea').value).then(() => alert('Script MikroTik berhasil disalin ke clipboard! Silakan paste di New Terminal Winbox.'));
}
generateScript();
</script>
@endpush
