@extends('portal.layout')
@section('title', 'Layanan Terisolir')
@section('header')
<header class="hdr">
    <img src="{{ asset('snet/logo.png') }}" alt="S.NET" class="h-logo">
    <div style="flex:1;min-width:0"><div class="h-n">Layanan Terisolir</div><div class="h-id">{{ config('snet.company') }}</div></div>
</header>
@endsection
@section('content')
<div class="card">
    <div class="cb" style="text-align:center">
        <div style="font-size:3rem">⚠️</div>
        <div style="font-weight:800;font-size:1.1rem;color:var(--red);margin:6px 0">Layanan internet dinonaktifkan sementara</div>
        @if ($customer)
            <div style="font-size:.85rem">Halo <strong>{{ \Illuminate\Support\Str::before($customer->full_name.' ', ' ') }}</strong>, silakan selesaikan tagihan berikut. Internet aktif kembali otomatis setelah pembayaran diterima.</div>
        @else
            <div style="font-size:.85rem">Silakan masuk ke <a href="{{ route('portal.login') }}">Portal Pelanggan</a> untuk melihat dan membayar tagihan.</div>
        @endif
    </div>
</div>
@if ($customer)
    @include('portal._pay', ['payUrl' => route('isolir.pay'), 'statusUrl' => null])
@endif
@endsection
