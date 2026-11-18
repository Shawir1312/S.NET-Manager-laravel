@extends('layouts.app')
@section('title', 'Kelola Admin')
@section('page-header')
<div class="page-header">
    <div><h1 class="page-title">Kelola Admin</h1><p class="page-subtitle">Manajemen akun administrator</p></div>
    <a href="{{ route('users.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Tambah Admin</a>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="card table-card">
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Status</th><th>Login Terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($users as $a)
            <tr>
                <td class="fw-600">{{ $a->username ?: $a->email }} @if ($a->is(auth()->user()))<span class="badge bg-secondary">Anda</span>@endif</td>
                <td>{{ $a->name ?: '-' }}</td>
                <td>
                    @if ($a->isAdmin())<span class="badge bg-danger">Super Admin</span>
                    @elseif ($a->role === \App\Enums\Role::Teknisi)<span class="badge bg-primary">Operator</span>
                    @else<span class="badge bg-secondary">{{ $a->role->label() }}</span>@endif
                </td>
                <td>@if ($a->is_active)<span class="badge bg-success">Aktif</span>@else<span class="badge bg-secondary">Nonaktif</span>@endif</td>
                <td style="font-size:.78rem;">{{ $a->last_login_at?->format('d/m/Y H:i') ?? '-' }}</td>
                <td>
                    <a href="{{ route('users.edit', $a) }}" class="btn btn-sm btn-outline-primary btn-icon"><i class="bi bi-pencil"></i></a>
                    @unless ($a->is(auth()->user()))
                    <form method="POST" action="{{ route('users.destroy', $a) }}" class="d-inline">
                        @csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus admin '{{ $a->username ?: $a->name }}'?"><i class="bi bi-trash"></i></button>
                    </form>
                    @endunless
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">Tidak ada data</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())<div class="card-body py-2">{{ $users->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
