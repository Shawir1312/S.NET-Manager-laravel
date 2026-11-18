@extends(auth()->check() ? 'layouts.app' : 'errors::minimal')
@section('title', 'Akses Ditolak')
@section('code', '403')
@section('message', 'Akses ditolak')
@section('content')
    <div class="card"><div class="card-body text-center py-5">
        <i class="bi bi-shield-lock display-5 text-danger"></i>
        <h2 class="h4 mt-3">Akses ditolak</h2>
        <p class="text-body-secondary mb-4">{{ $exception->getMessage() ?: 'Anda tidak memiliki izin untuk halaman ini.' }}</p>
        <a href="{{ route('dashboard') }}" class="btn btn-primary">Kembali ke Dashboard</a>
    </div></div>
@endsection
