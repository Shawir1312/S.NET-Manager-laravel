@extends('layouts.app')
@php $title = $user->exists ? 'Edit Admin' : 'Tambah Admin'; @endphp
@section('title', $title)
@section('page-header')
<div class="page-header">
    <div><h1 class="page-title">{{ $title }}</h1>
    <nav aria-label="breadcrumb"><ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="{{ route('users.index') }}">Admin</a></li>
        <li class="breadcrumb-item active">{{ $title }}</li>
    </ol></nav></div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row justify-content-center"><div class="col-12 col-lg-7">
<div class="card">
    <div class="card-header"><h5 class="card-title"><i class="bi bi-person-gear"></i> {{ $title }}</h5></div>
    <div class="card-body">
        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" autocomplete="off">
            @csrf @if ($user->exists) @method('PUT') @endif
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="username" required maxlength="64" value="{{ old('username', $user->username) }}" autocomplete="off">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" required maxlength="100" value="{{ old('name', $user->name) }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" class="form-control" name="email" required value="{{ old('email', $user->email) }}">
                    <div class="form-text">Login bisa memakai username atau email.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role</label>
                    <select class="form-select" name="role">
                        @foreach (\App\Enums\Role::cases() as $r)<option value="{{ $r->value }}" @selected(old('role', $user->role?->value ?? \App\Enums\Role::Teknisi->value) === $r->value)>{{ $r === \App\Enums\Role::Admin ? 'Super Admin' : $r->label() }}</option>@endforeach
                    </select>
                    <div class="form-text">Super Admin: semua fitur · Operator: pelanggan, voucher, monitoring, laporan &amp; config ONT · Viewer: hanya melihat perangkat.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Password @if ($user->exists)(kosongkan jika tidak diubah)@else<span class="text-danger">*</span>@endif</label>
                    <input type="password" class="form-control" name="password" autocomplete="new-password" @required(! $user->exists) minlength="8" placeholder="Min. 8 karakter, huruf & angka">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Ulangi Password</label>
                    <input type="password" class="form-control" name="password_confirmation" autocomplete="new-password" minlength="8">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="is_active">
                        <option value="1" @selected(old('is_active', $user->is_active ?? true))>Aktif</option>
                        <option value="0" @selected(! old('is_active', $user->is_active ?? true))>Nonaktif</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>{{ $user->exists ? 'Simpan Perubahan' : 'Tambah Admin' }}</button>
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
