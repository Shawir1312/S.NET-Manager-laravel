@extends('layouts.app')
@section('title', 'Paket / Profil PPPoE')
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-box me-2 text-primary"></i>Paket / Profil PPPoE</h1>
        <p class="page-subtitle">Daftar profil PPPoE yang ada langsung di dalam router MikroTik Anda.</p>
    </div>
    <a href="{{ $router ? route('pppoe-profiles.form', ['router_id' => $router->id]) : '#' }}" class="btn btn-primary {{ $router ? '' : 'disabled' }}"><i class="bi bi-plus-circle me-1"></i> Tambah Profil ke MikroTik</a>
</div>
@endsection
@section('content')
@include('partials.errors')
@if ($error)<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>{{ $error }}</div>@endif
<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-600">{{ count($profiles) }} Profil MikroTik</span>
            <form method="GET" class="d-flex align-items-center gap-2 m-0">
                <select name="router_id" class="form-select form-select-sm" style="width:250px" onchange="this.form.submit()">
                    @forelse ($routers as $rt)
                        <option value="{{ $rt->id }}" @selected($router?->id === $rt->id)>{{ $rt->name }} ({{ $rt->ip_address }})</option>
                    @empty
                        <option value="">Belum ada router</option>
                    @endforelse
                </select>
            </form>
        </div>
        <input type="text" id="table-search" class="form-control form-control-sm" style="width:220px" placeholder="Cari profil...">
    </div>
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr><th>Nama Profil</th><th>Local Address</th><th>Remote Address (Pool)</th><th>Rate Limit</th><th>Only One</th><th>Komentar</th><th width="100">Aksi</th></tr></thead>
            <tbody>
            @forelse ($profiles as $p)
                <tr>
                    <td><div class="fw-bold text-primary">{{ $p['name'] ?? '' }}</div></td>
                    <td>{{ ($p['local-address'] ?? '') ?: '-' }}</td>
                    <td><span class="badge bg-secondary">{{ ($p['remote-address'] ?? '') ?: '-' }}</span></td>
                    <td><code style="font-size:14px;color:#d946ef">{{ ($p['rate-limit'] ?? '') ?: 'Unlimited' }}</code></td>
                    <td>{{ $p['only-one'] ?? 'default' }}</td>
                    <td><span class="text-muted" style="font-size:13px">{{ ($p['comment'] ?? '') ?: '-' }}</span></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="{{ route('pppoe-profiles.form', ['router_id' => $router->id, 'id' => $p['.id'] ?? '']) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                            @unless (in_array($p['name'] ?? '', ['default', 'default-encryption'], true))
                                <form method="POST" action="{{ route('pppoe-profiles.destroy') }}" class="d-inline" onsubmit="return confirm('Hapus profil \'{{ addslashes($p['name'] ?? '') }}\' secara permanen dari MikroTik?')">
                                    @csrf @method('DELETE')<input type="hidden" name="router_id" value="{{ $router->id }}"><input type="hidden" name="id" value="{{ $p['.id'] ?? '' }}">
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus"><i class="bi bi-trash"></i></button>
                                </form>
                            @endunless
                        </div>
                    </td>
                </tr>
            @empty
                @unless ($error)<tr><td colspan="7" class="text-center text-muted py-4">Belum ada profil PPPoE di MikroTik ini.</td></tr>@endunless
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
