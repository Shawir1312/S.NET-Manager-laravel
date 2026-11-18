@extends('portal.layout')
@section('title', 'Cek Voucher')
@section('header')
<header class="hdr">
    <img src="{{ asset('snet/logo.png') }}" alt="S.NET" class="h-logo">
    <div style="flex:1;min-width:0"><div class="h-n">Voucher Hotspot</div><div class="h-id">Cek &amp; perpanjang voucher</div></div>
</header>
@endsection
@section('content')
@if (! empty($extended))<div class="alert aok">✅ Voucher berhasil diperpanjang. Silakan login hotspot dengan kode baru.</div>@endif
<div class="card">
    <div class="ch"><div class="ct">🎫 Cek Voucher</div></div>
    <div class="cb">
        <form method="GET" action="{{ route('voucher.check') }}" style="display:flex;gap:8px">
            <input class="fc" name="code" value="{{ request('code') }}" placeholder="Kode voucher" required style="font-family:'JetBrains Mono',monospace">
            <button class="btn btn-p">Cek</button>
        </form>
        @if (request()->filled('code') || ! empty($extended))
            @if ($voucher)
                <table class="info-table" style="margin-top:12px">
                    <tr><td style="color:var(--g400)">Kode</td><td><span class="code">{{ $voucher->username }}</span></td></tr>
                    <tr><td style="color:var(--g400)">Paket</td><td>{{ $voucher->profile->display_name ?: $voucher->profile->name }}</td></tr>
                    <tr><td style="color:var(--g400)">Status</td><td><span class="bdg {{ $voucher->status === 'active' ? 'bon' : ($voucher->status === 'expired' ? 'boff' : '') }}">{{ ['unused' => 'Belum dipakai', 'active' => 'Aktif', 'expired' => 'Habis'][$voucher->status] ?? $voucher->status }}</span></td></tr>
                    <tr><td style="color:var(--g400)">Berlaku s/d</td><td>{{ $voucher->expired_at?->format('d M Y H:i') ?? '-' }}</td></tr>
                    @if ($remaining !== null)<tr><td style="color:var(--g400)">Sisa waktu</td><td>{{ \Carbon\CarbonInterval::seconds($remaining)->cascade()->forHumans(short: true) }}</td></tr>@endif
                </table>
            @elseif (empty($extended))
                <div class="alert awrn" style="margin-top:12px">⚠️ Voucher tidak ditemukan.</div>
            @endif
        @endif
    </div>
</div>
<div class="card">
    <div class="ch"><div class="ct">🔁 Perpanjang Voucher</div></div>
    <div class="cb">
        @if (! empty($extendError))<div class="alert aerr">⚠️ {{ $extendError }}</div>@endif
        <form method="POST" action="{{ route('voucher.extend') }}">@csrf
            <div class="fg"><label class="fl">Kode voucher lama</label><input class="fc" name="old_code" required></div>
            <div class="fg"><label class="fl">Kode voucher baru (paket sama)</label><input class="fc" name="new_code" required></div>
            <button class="btn btn-p btn-full">Perpanjang</button>
        </form>
    </div>
</div>
@endsection
