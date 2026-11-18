@extends('layouts.app')
@section('title', 'Config GenieACS')

@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-hdd-rack me-2 text-primary"></i>Konfigurasi GenieACS</h1>
        <p class="page-subtitle">Kelola multi-server GenieACS (Auto-Configuration Server TR-069) untuk remote dan monitoring ONT.</p>
    </div>
    <a href="{{ route('settings.genieacs.edit', ['server' => 'new']) }}#formServer" class="btn btn-primary"><i class="bi bi-plus-circle me-1"></i> Tambah Server</a>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="card table-card mb-4">
    <div class="table-toolbar flex-wrap gap-2"><span class="fw-600">{{ $servers->count() }} Server</span></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Nama Server</th><th>NBI URL (Port 7557)</th><th>Status</th><th>Tes Terakhir</th><th>Aksi</th></tr></thead>
            <tbody>
            @forelse ($servers as $srv)
            <tr class="{{ $setting->id === $srv->id ? 'table-active' : '' }}">
                <td><div class="fw-bold">{{ $srv->name }}</div></td>
                <td><span class="font-mono">{{ $srv->nbi_url }}</span></td>
                <td>@if ($srv->is_active)<span class="badge bg-success">Aktif</span>@else<span class="badge bg-secondary">Nonaktif</span>@endif</td>
                <td>@if ($srv->last_test_status)<span class="badge {{ $srv->last_test_status === 'ok' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $srv->last_test_status === 'ok' ? 'Terhubung' : 'Gagal' }}</span>@else<span class="text-muted small">Belum dites</span>@endif</td>
                <td>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-success btn-icon test-conn-btn" data-id="{{ $srv->id }}" title="Tes Koneksi"><i class="bi bi-wifi"></i></button>
                        <a href="{{ route('settings.genieacs.edit', ['server' => $srv->id]) }}#formServer" class="btn btn-sm btn-outline-primary btn-icon" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('settings.genieacs.destroy', $srv) }}" class="d-inline">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus server '{{ $srv->name }}'? ONT yang terhubung ke server ini akan dilepas dari server." title="Hapus"><i class="bi bi-trash"></i></button></form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-center text-muted py-4">Belum ada server GenieACS yang dikonfigurasi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="row g-3" id="formServer">
    <div class="col-xl-8">
        <form method="POST" action="{{ route('settings.genieacs.update') }}" class="card" autocomplete="off">
            @csrf
            @method('PUT')
            @if ($setting->exists)<input type="hidden" name="server_id" value="{{ $setting->id }}">@else<input type="hidden" name="new_server" value="1">@endif
            <div class="card-header"><h5 class="card-title"><i class="bi bi-hdd-rack"></i> {{ $setting->exists ? 'Edit Server: '.$setting->name : 'Tambah Server GenieACS' }}</h5></div>
            <div class="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger">Periksa kembali isian yang ditandai.</div>
                @endif

                <div class="row g-3">
                    <div class="col-md-8"><label class="form-label" for="name">Nama server</label>
                        <input class="form-control" id="name" name="name" value="{{ old('name', $setting->name) }}" maxlength="100"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1" @checked(old('is_active', $setting->is_active ?? true))><label class="form-check-label" for="is_active">Aktif</label></div></div>
                    <div class="col-md-6">
                        <label class="form-label" for="nbi_url">GenieACS NBI URL <span class="text-danger">*</span></label>
                        <input class="form-control @error('nbi_url') is-invalid @enderror" id="nbi_url" name="nbi_url"
                               value="{{ old('nbi_url', $setting->nbi_url) }}" placeholder="http://192.168.20.254:7557" required>
                        @error('nbi_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">API NBI GenieACS, biasanya port 7557.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cwmp_url">GenieACS CWMP URL</label>
                        <input class="form-control @error('cwmp_url') is-invalid @enderror" id="cwmp_url" name="cwmp_url"
                               value="{{ old('cwmp_url', $setting->cwmp_url) }}" placeholder="http://192.168.20.254:7547">
                        @error('cwmp_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">ACS URL yang dipakai ONT (informasi).</div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="username">API Username</label>
                        <input class="form-control @error('username') is-invalid @enderror" id="username" name="username" value=""
                               placeholder="{{ $hasCredentials ? 'Tersimpan — kosongkan untuk tidak mengubah' : 'Kosongkan jika NBI tanpa autentikasi' }}"
                               autocomplete="off">
                        @error('username')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="password">API Password / Token</label>
                        <input class="form-control @error('password') is-invalid @enderror" id="password" name="password" type="password" value=""
                               placeholder="{{ $hasCredentials ? '•••••• (tersimpan terenkripsi)' : 'Kosongkan jika tidak dipakai' }}"
                               autocomplete="new-password">
                        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if ($hasCredentials)
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="clear_credentials" id="clear_credentials" value="1">
                                <label class="form-check-label" for="clear_credentials">Hapus credential tersimpan</label>
                            </div>
                        </div>
                    @endif

                    <div class="col-sm-4">
                        <label class="form-label" for="connect_timeout">Connection Timeout (detik)</label>
                        <input class="form-control @error('connect_timeout') is-invalid @enderror" type="number" min="1" max="60"
                               id="connect_timeout" name="connect_timeout" value="{{ old('connect_timeout', $setting->connect_timeout) }}" required>
                        @error('connect_timeout')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="request_timeout">Request Timeout (detik)</label>
                        <input class="form-control @error('request_timeout') is-invalid @enderror" type="number" min="5" max="300"
                               id="request_timeout" name="request_timeout" value="{{ old('request_timeout', $setting->request_timeout) }}" required>
                        @error('request_timeout')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="connection_request_timeout">Connection Request Timeout (detik)</label>
                        <input class="form-control @error('connection_request_timeout') is-invalid @enderror" type="number" min="1" max="120"
                               id="connection_request_timeout" name="connection_request_timeout"
                               value="{{ old('connection_request_timeout', $setting->connection_request_timeout) }}" required>
                        @error('connection_request_timeout')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="hidden" name="verify_tls" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="verify_tls" id="verify_tls" value="1"
                                   @checked(old('verify_tls', $setting->verify_tls))>
                            <label class="form-check-label" for="verify_tls">Verifikasi sertifikat TLS (untuk NBI lewat HTTPS)</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i> Simpan</button>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-activity"></i> Status Koneksi</h5></div>
            <div class="card-body">
                <div id="connStatus" class="mb-3">
                    @if ($setting->last_test_at)
                        <span class="badge text-bg-{{ $setting->last_test_status === 'ok' ? 'success' : 'danger' }}">
                            {{ $setting->last_test_status === 'ok' ? 'TERHUBUNG' : 'GAGAL' }}
                        </span>
                        <div class="small mt-2">{{ $setting->last_test_message }}</div>
                        <div class="small text-body-secondary">Dites {{ $setting->last_test_at->diffForHumans() }}</div>
                    @else
                        <span class="badge text-bg-secondary">BELUM DITES</span>
                    @endif
                </div>
                <button type="button" class="btn btn-outline-primary w-100" id="btnTest" @disabled(! $setting->exists)>
                    <i class="bi bi-plug me-1"></i> TEST CONNECTION
                </button>
                <div class="form-text">Menguji pengaturan yang sudah <strong>disimpan</strong>. Simpan dulu bila ada perubahan.</div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('.test-conn-btn').forEach(btn => btn.addEventListener('click', async () => {
        const icon = btn.innerHTML;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span>';
        btn.disabled = true;
        try {
            const r = await OntApp.api('{{ route('api.genieacs.test') }}', { method: 'POST', body: { server_id: Number(btn.dataset.id) } });
            OntApp.toast(r.message, r.ok ? 'success' : 'danger');
        } catch (err) {
            OntApp.toast(err.message, 'danger');
        } finally {
            btn.innerHTML = icon;
            btn.disabled = false;
        }
    }));
    document.getElementById('btnTest')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget, box = document.getElementById('connStatus');
        btn.disabled = true;
        box.innerHTML = '<span class="badge text-bg-info">MENGUJI…</span>';
        try {
            const r = await OntApp.api('{{ route('api.genieacs.test') }}', { method: 'POST', body: { server_id: @json($setting->id) } });
            box.innerHTML = '';
            const badge = document.createElement('span');
            badge.className = 'badge text-bg-' + (r.ok ? 'success' : 'danger');
            badge.textContent = r.ok ? 'TERHUBUNG' : 'GAGAL';
            const msg = document.createElement('div');
            msg.className = 'small mt-2';
            msg.textContent = r.message;
            box.append(badge, msg);
            OntApp.toast(r.message, r.ok ? 'success' : 'danger');
        } catch (err) {
            box.innerHTML = '<span class="badge text-bg-danger">GAGAL</span>';
            OntApp.toast(err.message, 'danger');
        } finally {
            btn.disabled = false;
        }
    });
</script>
@endpush
