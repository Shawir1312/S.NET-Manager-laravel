@extends('portal.layout')
@section('title', 'Portal Pelanggan')
@php($rp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.'))
@php($hasUnpaid = count($bills) > 0)
@section('header')
<header class="hdr">
    <img src="{{ asset('snet/logo.png') }}" alt="S.NET" class="h-logo">
    <div style="flex:1;min-width:0">
        <div class="h-n">{{ $customer->full_name }}</div>
        <div class="h-id">{{ $customer->pppoe_username }}</div>
    </div>
    <div style="display:flex;align-items:center;gap:6px">
        <button type="button" class="h-theme-btn" id="portal-theme-btn" title="Ganti Mode Gelap/Terang" onclick="togglePortalTheme()"><span class="p-moon-icon">🌙</span><span class="p-sun-icon" style="display:none">☀️</span></button>
        <form method="POST" action="{{ route('portal.logout') }}" style="display:inline">@csrf<button type="submit" class="h-lo" style="border:none;cursor:pointer">⏻ Keluar</button></form>
    </div>
</header>
@endsection

@section('content')
@foreach ($announcements as $a)<div class="alert ainf">📢 <strong>{{ $a->title }}</strong> — {{ $a->body }}</div>@endforeach

<div class="sbar">
    <div class="sdot {{ $online ? 'on' : 'off' }}"></div>
    <div style="flex:1;min-width:0">
        <div style="font-weight:700;font-size:.88rem">{{ $device?->product_class ?: 'Modem WiFi' }}</div>
        <div style="font-size:.72rem;color:var(--g400)">SN: <span class="ipm">{{ $customer->ont_sn ?: '—' }}</span>@if ($device?->last_inform_at) &bull; {{ $device->last_inform_at->diffForHumans() }}@endif</div>
    </div>
    <span style="font-weight:700;font-size:.84rem;color:{{ $online ? '#16A34A' : 'var(--red)' }}">{{ $online ? '● Online' : '● Offline' }}</span>
</div>

@if ($customer->status === 'isolated')<div class="alert aerr">⚠️ Layanan Anda sedang terisolir. Selesaikan tagihan di tab <strong>Tagihan</strong>, internet aktif kembali otomatis.</div>@endif
@unless ($customer->ont_sn)<div class="alert ainf">ℹ️ ONT belum terdaftar. Hubungi admin S.NET untuk aktivasi.</div>@endunless

@if ($customer->ont_wifi_ssid)
<div class="wfc-cur">
    <div class="wfb" style="flex:1">
        <div class="wfb-band" style="color:var(--blue-d)">📡 Nama WiFi Aktif (2.4G &amp; 5G)</div>
        <div class="wfb-ssid">{{ $customer->ont_wifi_ssid }}</div>
        <div class="wfb-pass">
            <span class="pw-val" id="pw24" data-val="{{ $customer->ont_wifi_pass }}" data-show="0">{{ $customer->ont_wifi_pass ? '••••••••' : '(Belum diatur)' }}</span>
            @if ($customer->ont_wifi_pass)
                <button class="cbtn" type="button" onclick="tpw('pw24',this)" title="Lihat/Sembunyikan Sandi">👁</button>
                <button class="cbtn" type="button" onclick="cpTxt(document.getElementById('pw24').dataset.val, this)" title="Salin Sandi">📋</button>
            @endif
        </div>
    </div>
</div>
@endif

<div class="tabnav">
    @foreach (['wifi' => ['✏️', 'WiFi'], 'traffic' => ['📈', 'Live Traffic'], 'billing' => ['💳', 'Tagihan'], 'settings' => ['⚙️', 'Setting']] as $id => [$icon, $label])
        <button type="button" class="tab {{ $loop->first ? 'on' : '' }}" data-tab="{{ $id }}" onclick="sw('{{ $id }}')">
            <span class="t-icon">{{ $icon }}</span><span class="t-txt">{{ $label }}</span>
            @if ($id === 'billing' && $hasUnpaid)<span class="tab-badge-dot" title="Ada tagihan belum diselesaikan"></span>@endif
        </button>
    @endforeach
</div>

<div class="tp on" id="tp-wifi">
    @if (! $customer->ont_sn)
        <div class="alert ainf">Perangkat tidak terdeteksi — hubungi admin jika ingin mengubah WiFi.</div>
    @else
        <div class="card">
            <div class="ch"><div class="ct">✏️ Ubah Nama &amp; Sandi WiFi (2.4G &amp; 5G)</div></div>
            <div class="cb">
                <form method="POST" action="{{ route('portal.wifi') }}">
                    @csrf
                    <div class="alert ainf" style="margin-bottom:14px">📋 <strong>Info:</strong> Nama WiFi (SSID) dan Password baru otomatis diterapkan pada <strong>2.4 GHz dan 5 GHz</strong>.</div>
                    <div class="fg">
                        <label class="fl">📡 Nama WiFi Baru (SSID)</label>
                        <input type="text" name="ssid" class="fc" maxlength="32" placeholder="Contoh: {{ $customer->ont_wifi_ssid ?: 'Nama WiFi Baru' }}" value="{{ old('ssid', $customer->ont_wifi_ssid) }}">
                        <div class="fhint">Nama WiFi ini berlaku untuk seluruh perangkat di frekuensi 2.4 GHz dan 5 GHz.</div>
                    </div>
                    <div class="fg">
                        <label class="fl">🔑 Password WiFi Baru</label>
                        <input type="password" name="password" class="fc" placeholder="Minimal 8 karakter (kosong = tidak diubah)" maxlength="63" minlength="8" autocomplete="new-password">
                        <div class="fhint">⚠️ Password yang sama dikirim ke 2.4 GHz dan 5 GHz. Semua perangkat harus pakai password ini.</div>
                    </div>
                    <button type="submit" class="btn btn-p btn-full">💾 Simpan &amp; Terapkan ke Modem</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="ch"><div class="ct">🔄 Restart Modem</div></div>
            <div class="cb">
                <form method="POST" action="{{ route('portal.reboot') }}" onsubmit="return confirm('Restart modem? Internet terputus 1-2 menit.')">@csrf
                    <button type="submit" class="btn btn-o btn-full">🔄 Restart Modem Sekarang</button></form>
            </div>
        </div>
    @endif
</div>

<div class="tp" id="tp-traffic">
    <div class="card">
        <div class="ch"><div class="ct">📈 Live Traffic</div><span class="live-pill" id="liveState">menunggu…</span></div>
        <div class="cb">
            <div class="live-speed-grid">
                <div class="speed-card speed-card-dl"><div class="speed-card-header">⬇️ Download</div><div class="speed-val-box"><span class="speed-num" id="dl">0</span> <span class="speed-unit" id="dlu">kbps</span></div></div>
                <div class="speed-card speed-card-ul"><div class="speed-card-header">⬆️ Upload</div><div class="speed-val-box"><span class="speed-num" id="ul">0</span> <span class="speed-unit" id="ulu">kbps</span></div></div>
            </div>
        </div>
    </div>
</div>

<div class="tp" id="tp-billing">
    @include('portal._pay', ['payUrl' => route('portal.pay'), 'statusUrl' => route('portal.pay.status')])
    <div class="card">
        <div class="ch"><div class="ct">📋 Informasi Layanan &amp; Langganan</div></div>
        <div class="cb">
            <table class="info-table">
                <tr><td style="color:var(--g400)">Nama Pelanggan</td><td>{{ $customer->full_name }}</td></tr>
                <tr><td style="color:var(--g400)">Username PPPoE</td><td><span class="code">{{ $customer->pppoe_username }}</span></td></tr>
                <tr><td style="color:var(--g400)">Paket Internet</td><td><span class="bdg" style="background:#DBEAFE;color:#1D4ED8">{{ $customer->profile ?: 'Standar' }}</span></td></tr>
                <tr><td style="color:var(--g400)">Biaya Berlangganan</td><td>{!! $customer->is_free ? '<span class="bdg bon">Gratis</span>' : e($rp($customer->monthly_price)).' / bulan' !!}</td></tr>
                <tr><td style="color:var(--g400)">Jatuh Tempo Tiap Bulan</td><td>Tanggal <strong>{{ $customer->due_day }}</strong></td></tr>
                <tr><td style="color:var(--g400)">Status Layanan</td><td>
                    @if ($customer->status === 'active')<span class="bdg bon">● Aktif Normal</span>@elseif ($customer->status === 'isolated')<span class="bdg boff">● Terisolir (Tunggakan)</span>@else<span class="bdg boff">{{ ucfirst($customer->status) }}</span>@endif
                </td></tr>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="ch"><div class="ct">🧾 Riwayat Pembayaran</div></div>
        <div class="cb">
            <table class="bill-table">
                @forelse ($payments as $p)
                    <tr>
                        <td>{{ \App\Services\Pppoe\BillingService::MONTHS[$p->period_month] }} {{ $p->period_year }}<div style="font-size:.7rem;color:var(--g400)">{{ $p->paid_at?->format('d M Y H:i') }} &bull; {{ strtoupper($p->payment_method) }}</div></td>
                        <td style="text-align:right;font-weight:700">{{ $rp($p->amount) }}</td>
                        <td style="text-align:right"><a class="btn btn-o btn-sm" target="_blank" href="{{ route('receipt.show', ['payment' => $p, 'signature' => \App\Services\Pppoe\BillingService::receiptSignature($p)]) }}">🧾 Kwitansi</a></td>
                    </tr>
                @empty
                    <tr><td style="color:var(--g400)">Belum ada pembayaran.</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</div>

<div class="tp" id="tp-settings">
    <div class="card">
        <div class="ch"><div class="ct">🔑 Ubah Password Portal</div></div>
        <div class="cb">
            <form method="POST" action="{{ route('portal.password') }}">@csrf
                <div class="fg"><label class="fl">Password Lama</label><input type="password" name="current_password" class="fc" required autocomplete="current-password"></div>
                <div class="pass-grid">
                    <div class="fg"><label class="fl">Password Baru</label><input type="password" name="password" class="fc" required minlength="6" autocomplete="new-password"></div>
                    <div class="fg"><label class="fl">Konfirmasi</label><input type="password" name="password_confirmation" class="fc" required minlength="6" autocomplete="new-password"></div>
                </div>
                <button type="submit" class="btn btn-p">🔑 Ubah Password</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="ch"><div class="ct">📡 Info Perangkat</div></div>
        <div class="cb"><div style="display:grid;gap:7px;font-size:.82rem">
            @foreach (['Username PPPoE' => $customer->pppoe_username, 'Nama' => $customer->full_name, 'Modem' => $device?->product_class ?: '—', 'Serial' => $customer->ont_sn ?: '—', 'Status' => $online ? 'Online' : 'Offline'] as $l => $v)
                <div style="display:flex;gap:10px;padding:4px 0;border-bottom:1px solid var(--g100)">
                    <div style="font-size:.67rem;font-weight:700;color:var(--g400);text-transform:uppercase;width:90px;flex-shrink:0;padding-top:2px">{{ $l }}</div><div>{{ $v }}</div>
                </div>
            @endforeach
        </div></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let trafficTimer = null;
function sw(id) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('on'));
    document.querySelectorAll('.tp').forEach(p => p.classList.remove('on'));
    document.querySelector(`.tab[data-tab="${id}"]`)?.classList.add('on');
    document.getElementById('tp-' + id)?.classList.add('on');
    clearInterval(trafficTimer);
    if (id === 'traffic') { pollTraffic(); trafficTimer = setInterval(pollTraffic, 3000); }
}
function fmt(b) { return b >= 1e6 ? [(b / 1e6).toFixed(1), 'Mbps'] : [(b / 1e3).toFixed(0), 'kbps']; }
async function pollTraffic() {
    const r = await fetch(@json(route('portal.traffic'))).then(r => r.json()).catch(() => null);
    document.getElementById('liveState').textContent = r && r.online ? '● Live' : 'Tidak terhubung';
    if (!r || !r.online) return;
    const [d, du] = fmt(r.download), [u, uu] = fmt(r.upload);
    document.getElementById('dl').textContent = d; document.getElementById('dlu').textContent = du;
    document.getElementById('ul').textContent = u; document.getElementById('ulu').textContent = uu;
}
@if ($hasUnpaid || request('paid')) sw('billing'); @endif
</script>
@endpush
