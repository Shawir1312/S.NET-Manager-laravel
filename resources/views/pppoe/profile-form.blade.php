@extends('layouts.app')
@php $pageTitle = $id ? 'Edit Profil PPPoE' : 'Tambah Profil PPPoE'; $locked = in_array($profile['name'], ['default', 'default-encryption'], true); @endphp
@section('title', $pageTitle)
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $pageTitle }}</h1>
        <nav aria-label="breadcrumb"><ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('pppoe-profiles.index', ['router_id' => $router->id]) }}">Profil PPPoE</a></li>
            <li class="breadcrumb-item active">{{ $pageTitle }}</li>
        </ol></nav>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row justify-content-center">
<div class="col-12 col-lg-8">
<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="bi bi-box"></i> {{ $pageTitle }} (MikroTik)</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ route('pppoe-profiles.store') }}">
            @csrf <input type="hidden" name="router_id" value="{{ $router->id }}">
            @if ($id)<input type="hidden" name="id" value="{{ $id }}">@endif
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label">Nama Profil <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required value="{{ old('name', $profile['name']) }}" @readonly($locked) placeholder="Contoh: Paket 10 Mbps">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Local Address</label>
                    <input type="text" class="form-control" name="local_address" value="{{ old('local_address', $profile['local-address']) }}" placeholder="IP Gateway (cth: 192.168.10.1)">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Remote Address (Pool)</label>
                    <select class="form-select" name="remote_address">
                        <option value="">-- Kosong --</option>
                        @foreach ($pools as $pool)<option value="{{ $pool }}" @selected(old('remote_address', $profile['remote-address']) === $pool)>{{ $pool }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rate Limit (Rx/Tx)</label>
                    <input type="text" class="form-control" name="rate_limit" value="{{ old('rate_limit', $profile['rate-limit']) }}" placeholder="cth: 5M/10M">
                    <div class="form-text">Batas kecepatan (Upload/Download). Cth: <code>5M/10M</code></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Only One</label>
                    <select class="form-select" name="only_one">
                        @foreach (['default' => 'Default', 'yes' => 'Yes (1 Akun 1 Sesi)', 'no' => 'No (Bisa multiple sesi)'] as $v => $l)<option value="{{ $v }}" @selected(old('only_one', $profile['only-one']) === $v)>{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Komentar</label>
                    <input type="text" class="form-control" name="comment" value="{{ old('comment', $profile['comment']) }}">
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>{{ $id ? 'Simpan ke MikroTik' : 'Tambah ke MikroTik' }}</button>
                <a href="{{ route('pppoe-profiles.index', ['router_id' => $router->id]) }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
