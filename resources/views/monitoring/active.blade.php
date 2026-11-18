@extends('layouts.app')
@section('title', 'User Aktif')
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">User Aktif</h1>
        <p class="page-subtitle">Sesi hotspot yang sedang berlangsung (real-time dari radacct)</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="d-flex align-items-center gap-2 me-auto">
            <span class="badge bg-success fs-6" id="active-count">—</span>
            <span class="text-muted" style="font-size:.8rem;">user aktif</span>
        </div>
        <button class="btn btn-outline-primary btn-sm" onclick="refreshActiveUsers()"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
        <button class="btn btn-outline-success btn-sm" onclick="activeAction('sync_mikrotik', this)" id="btnSyncMikrotik" title="Cocokkan sesi aktif dengan user yang benar-benar online di MikroTik"><i class="bi bi-arrow-repeat me-1"></i>Sinkron dari MikroTik</button>
        <button class="btn btn-outline-danger btn-sm" onclick="activeAction('clear_offline_ghosts', this)" id="btnClearGhosts" title="Bersihkan sesi menggantung dari router yang sedang offline"><i class="bi bi-trash3 me-1"></i>Bersihkan Sesi Router Offline</button>
    </div>
</div>
@endsection
@section('content')
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <label class="form-label mb-0 fw-600">Filter Router:</label>
            <select class="form-select form-select-sm" style="width:220px;" id="filter-router" onchange="refreshActiveUsers()">
                <option value="">Semua Router</option>
                @foreach ($all_routers as $r)<option value="{{ $r->id }}" @selected($filter_router === $r->id)>{{ $r->name }} — {{ $r->ip_address }}</option>@endforeach
            </select>
            <div class="text-muted ms-auto d-flex align-items-center gap-2" style="font-size:.75rem;">
                <span class="badge bg-success-subtle text-success border border-success-subtle py-1 d-inline-flex align-items-center gap-1">
                    <span class="spinner-grow spinner-grow-sm text-success" style="width:6px;height:6px;" role="status"></span>
                    Live Realtime (4s)
                </span>
                <span id="last-refresh">Memuat...</span>
            </div>
        </div>
    </div>
</div>

<div class="card table-card">
    <div class="table-responsive">
        <table class="table" id="active-users-table">
            <thead><tr><th>Username</th><th>Router / NAS</th><th>MAC Address</th><th>IP Address</th><th>Durasi</th><th>Sisa Waktu</th><th>Download</th><th>Upload</th><th>Profil</th><th>Aksi</th></tr></thead>
            <tbody>
                <tr><td colspan="10" class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div>Memuat data...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
const ACTIVE_URL = @json(route('monitoring.active')), ACTION_URL = @json(route('monitoring.active.action')), DISCONNECT_URL = @json(route('monitoring.disconnect'));
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
const postJson = (url, data) => fetch(url, {method: 'POST', headers: {'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify(data)}).then(r => r.json());

function refreshActiveUsers() {
    const router = document.getElementById('filter-router')?.value || '';
    const tbody = document.querySelector('#active-users-table tbody');
    fetch(`${ACTIVE_URL}?format=json&router_id=${encodeURIComponent(router)}&_t=${Date.now()}`, {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(data => {
            document.getElementById('active-count').textContent = data.count || 0;
            document.getElementById('last-refresh').textContent = 'Diperbarui: ' + new Date().toLocaleTimeString('id-ID');
            if (!data.users || data.users.length === 0) {
                tbody.innerHTML = `<tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-wifi-off display-4 d-block mb-2"></i>Tidak ada user aktif</td></tr>`;
                return;
            }
            tbody.innerHTML = data.users.map(u => `
                <tr>
                    <td class="font-mono fw-600">${escHtml(u.username)}</td>
                    <td><div style="font-size:.8rem;">${escHtml(u.router_name || '-')}</div><div class="font-mono text-muted" style="font-size:.7rem;">${escHtml(u.nasipaddress)}</div></td>
                    <td class="font-mono" style="font-size:.75rem;">${escHtml(u.callingstationid || '-')}</td>
                    <td class="font-mono" style="font-size:.75rem;">${escHtml(u.framedipaddress || '-')}</td>
                    <td><span class="badge bg-primary">${escHtml(u.duration)}</span></td>
                    <td>${u.sisa_waktu || '-'}</td>
                    <td class="text-success">↓ ${escHtml(u.dl)}</td>
                    <td class="text-primary">↑ ${escHtml(u.ul)}</td>
                    <td style="font-size:.78rem;">${escHtml(u.profile || '-')}</td>
                    <td><button class="btn btn-danger btn-sm btn-icon" title="Disconnect" onclick="disconnectUser('${escHtml(u.username)}', '${escHtml(u.radacctid)}', this)"><i class="bi bi-x-circle"></i></button></td>
                </tr>`).join('');
        })
        .catch(() => {
            if (tbody.children.length === 0) tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-3"><i class="bi bi-exclamation-triangle me-2"></i>Gagal memuat data</td></tr>`;
        });
}

function disconnectUser(username, radacctid, btn) {
    if (!confirm(`Putuskan sesi ${username}?`)) return;
    btn.disabled = true;
    postJson(DISCONNECT_URL, {radacctid}).then(d => {
        showToast(d.success ? `User ${username} berhasil di-disconnect.` : 'Gagal disconnect.', d.success ? 'success' : 'error');
        refreshActiveUsers();
    }).catch(() => btn.disabled = false);
}

function activeAction(action, btn) {
    if (action === 'clear_offline_ghosts' && !confirm('Apakah Anda yakin ingin membersihkan seluruh sesi menggantung dari router yang sedang offline?')) return;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Memproses...';
    postJson(ACTION_URL, {action, router_id: document.getElementById('filter-router').value || null})
        .then(d => {
            alert(action === 'sync_mikrotik'
                ? `✓ Sukses Sinkronisasi MikroTik!\n• User Aktif Terdeteksi: ${d.active_count}\n• Ghost session ditutup: ${d.closed}`
                : `✓ Sukses! ${d.closed_count || 0} sesi menggantung dari router offline berhasil ditutup.`);
            refreshActiveUsers();
        })
        .catch(err => alert('Gagal menghubungi server: ' + err.message))
        .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}

refreshActiveUsers();
setInterval(refreshActiveUsers, 4000);
</script>
@endsection
