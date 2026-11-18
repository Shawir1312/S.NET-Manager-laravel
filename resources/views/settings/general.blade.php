@extends('layouts.app')
@section('title', 'Pengaturan')
@php $rs = $radiusStatus; $db = config('database.connections.'.config('database.default')); @endphp
@section('page-header')
<div class="page-header">
    <div><h1 class="page-title"><i class="bi bi-gear-wide-connected me-2 text-primary"></i>Pengaturan Aplikasi</h1><p class="page-subtitle">Konfigurasi umum aplikasi &amp; status service server</p></div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row g-4">
    <div class="col-12">
        <div class="card {{ $rs['is_active'] ? 'border-success' : 'border-danger' }} shadow-sm">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 py-3 {{ $rs['is_active'] ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10' }}">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="card-title mb-0 fw-bold"><i class="bi bi-hdd-network-fill me-2 {{ $rs['is_active'] ? 'text-success' : 'text-danger' }}"></i>Status Service FreeRADIUS</h5>
                    <span class="badge bg-secondary font-monospace">{{ $rs['service_name'] }}.service</span>
                </div>
                <div>
                    @if ($rs['is_active'])
                        <span class="badge bg-success fs-6 px-3 py-2 rounded-pill d-inline-flex align-items-center"><span class="spinner-grow spinner-grow-sm me-2" role="status" style="width:0.6rem;height:0.6rem;"></span><strong>AKTIF (RUNNING)</strong></span>
                    @else
                        <span class="badge bg-danger fs-6 px-3 py-2 rounded-pill d-inline-flex align-items-center"><i class="bi bi-x-circle-fill me-2"></i><strong>NONAKTIF / BERHENTI</strong></span>
                    @endif
                </div>
            </div>
            <div class="card-body p-4">
                @unless ($rs['is_active'])
                    <div class="alert alert-danger d-flex gap-2 align-items-start mb-4">
                        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
                        <div>
                            <strong>Peringatan: Service FreeRADIUS Sedang Mati / Berhenti (atau tidak terdeteksi)!</strong><br>
                            Router MikroTik tidak dapat melakukan autentikasi login voucher hotspot maupun PPPoE saat service FreeRADIUS tidak aktif.
                            Klik tombol <strong>"Restart Service"</strong> di bawah atau jalankan perintah SSH berikut di terminal:
                            <code class="d-block mt-1 p-2 bg-dark text-white rounded">sudo systemctl start freeradius &amp;&amp; sudo systemctl enable freeradius</code>
                        </div>
                    </div>
                @endunless
                <div class="row g-3 text-center mb-4">
                    <div class="col-6 col-md-3"><div class="p-3 border rounded h-100 bg-light">
                        <div class="text-muted small mb-1">Status Mesin RADIUS</div>
                        <div class="fw-bold fs-5 {{ $rs['is_active'] ? 'text-success' : 'text-danger' }}">{{ $rs['status_label'] }}</div>
                        <div class="text-muted small mt-1 font-monospace">PID: {{ $rs['pid'] ? '#'.$rs['pid'] : '-' }}</div>
                    </div></div>
                    @foreach ([1812 => ['Port Autentikasi (Auth)', 'Login Hotspot & PPPoE'], 1813 => ['Port Akuntansi (Acct)', 'Catatan Kuota & Sesi']] as $port => [$label, $hint])
                    <div class="col-6 col-md-3"><div class="p-3 border rounded h-100 bg-light">
                        <div class="text-muted small mb-1">{{ $label }}</div>
                        <div class="fw-bold fs-5 {{ $rs['port_'.$port] ? 'text-success' : 'text-danger' }}">UDP {{ $port }}</div>
                        <div class="mt-1">@if ($rs['port_'.$port])<span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2"></i> Listening / Terbuka</span>@else<span class="badge bg-secondary">Tidak Terdeteksi</span>@endif</div>
                        <div class="text-muted small mt-1" style="font-size:0.75rem;">{{ $hint }}</div>
                    </div></div>
                    @endforeach
                    <div class="col-6 col-md-3"><div class="p-3 border rounded h-100 bg-light">
                        <div class="text-muted small mb-1">Versi</div>
                        <div class="fw-bold fs-6 text-dark text-truncate" title="{{ $rs['version'] ?: 'FreeRADIUS 3.x' }}">{{ $rs['version'] ?: 'FreeRADIUS 3.x' }}</div>
                    </div></div>
                </div>
                <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center pt-2 border-top">
                    <div class="d-flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('settings.radius.restart') }}" class="d-inline" onsubmit="return confirm('Restart service FreeRADIUS di server?\n\nService akan direfresh dan membaca ulang konfigurasi.');">
                            @csrf<button type="submit" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Restart Service FreeRADIUS</button>
                        </form>
                        <form method="POST" action="{{ route('routers.sync-radius') }}" class="d-inline" onsubmit="return confirm('Sinkronkan seluruh router NAS, voucher, dan akun PPPoE ke FreeRADIUS sekarang?');">
                            @csrf<button type="submit" class="btn btn-outline-warning text-dark fw-bold"><i class="bi bi-arrow-repeat me-1"></i>Sinkronisasi Database FreeRADIUS</button>
                        </form>
                    </div>
                    <a href="{{ route('settings.general') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Refresh Status</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-info-circle"></i> Informasi Sistem</h5></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><th>Versi Aplikasi</th><td>{{ config('snet.version') }}</td></tr>
                    <tr><th>PHP Version</th><td>{{ PHP_VERSION }}</td></tr>
                    <tr><th>Laravel</th><td>{{ app()->version() }}</td></tr>
                    <tr><th>Database</th><td>{{ $db['database'] ?? '-' }} @ {{ $db['host'] ?? config('database.default') }}</td></tr>
                    <tr><th>Timezone</th><td>{{ config('app.timezone') }}</td></tr>
                    <tr><th>Waktu Server</th><td>{{ now()->format('d M Y H:i:s') }}</td></tr>
                </table>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card h-100">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-database"></i> Status Database</h5></div>
            <div class="card-body">
                @foreach ($tableCounts as $t => $cnt)
                <div class="d-flex justify-content-between border-bottom py-1" style="font-size:.82rem;"><span class="font-mono">{{ $t }}</span><span class="badge bg-secondary">{{ number_format($cnt) }} baris</span></div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <form method="POST" action="{{ route('settings.announcements.store') }}" class="card h-100">
            @csrf
            <div class="card-header"><h5 class="card-title"><i class="bi bi-megaphone"></i> Pengumuman Portal Pelanggan</h5></div>
            <div class="card-body vstack gap-2">
                <div><label class="form-label">Jenis</label><select class="form-select" name="type">
                    @foreach (['info' => 'Info', 'warning' => 'Peringatan', 'success' => 'Kabar baik', 'release' => 'Fitur baru', 'maintenance' => 'MAINTENANCE (blokir login portal)'] as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div><label class="form-label">Judul <span class="text-danger">*</span></label><input class="form-control" name="title" required maxlength="200"></div>
                <div><label class="form-label">Isi <span class="text-danger">*</span></label><textarea class="form-control" name="body" rows="4" required></textarea></div>
                <div class="row g-2">
                    <div class="col-6"><label class="form-label small">Mulai</label><input class="form-control" type="datetime-local" name="start_at"></div>
                    <div class="col-6"><label class="form-label small">Selesai</label><input class="form-control" type="datetime-local" name="end_at"></div>
                </div>
                <div class="text-end"><button class="btn btn-primary"><i class="bi bi-megaphone me-1"></i> Tambah Pengumuman</button></div>
            </div>
        </form>
    </div>
    <div class="col-12 col-lg-7">
        <div class="card table-card h-100">
            <div class="table-toolbar"><span class="fw-600">Daftar Pengumuman</span></div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Pengumuman</th><th>Periode</th><th class="text-end">Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($announcements as $a)
                        <tr>
                            <td><span class="badge {{ $a->maintenance_mode ? 'bg-danger' : 'bg-info' }}">{{ $a->type }}</span> <strong>{{ $a->title }}</strong><div class="small text-muted">{{ \Illuminate\Support\Str::limit($a->body, 140) }}</div></td>
                            <td class="small text-muted text-nowrap">{{ $a->start_at?->format('d M H:i') ?? 'sekarang' }} – {{ $a->end_at?->format('d M H:i') ?? 'seterusnya' }}</td>
                            <td class="text-end text-nowrap">
                                <form method="POST" action="{{ route('settings.announcements.toggle', $a) }}" class="d-inline">@csrf<button class="btn btn-sm {{ $a->is_active ? 'btn-success' : 'btn-outline-secondary' }}">{{ $a->is_active ? 'Aktif' : 'Nonaktif' }}</button></form>
                                <form method="POST" action="{{ route('settings.announcements.destroy', $a) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus pengumuman ini?"><i class="bi bi-trash"></i></button></form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="text-center text-muted py-4">Belum ada pengumuman.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header"><h5 class="card-title"><i class="bi bi-terminal"></i> Konfigurasi FreeRADIUS</h5></div>
            <div class="card-body">
                <p class="text-muted">Tambahkan konfigurasi berikut ke file FreeRADIUS Anda:</p>
                <div class="bg-dark text-light p-3 rounded" style="font-family:monospace;font-size:.8rem;">
<pre class="mb-0 text-light"># /etc/freeradius/3.0/mods-available/sql
sql {
    driver = "rlm_sql_mysql"
    dialect = "mysql"
    server = "{{ $db['host'] ?? '127.0.0.1' }}"
    port = {{ $db['port'] ?? 3306 }}
    login = "{{ $db['username'] ?? 'radius' }}"
    password = "***"
    radius_db = "{{ $db['database'] ?? 'radius' }}"
    acct_table1 = "radacct"
    acct_table2 = "radacct"
    postauth_table = "radpostauth"
    authcheck_table = "radcheck"
    authreply_table = "radreply"
    groupcheck_table = "radgroupcheck"
    groupreply_table = "radgroupreply"
    usergroup_table = "radusergroup"
    nas_table = "nas"
    sql_user_name = "%{%{Stripped-User-Name}:-%{%{User-Name}:-DEFAULT}}"
    simul_count_query = "SELECT COUNT(*) FROM ${acct_table1} WHERE username = '%{SQL-User-Name}' AND acctstoptime IS NULL"
    read_clients = yes
    client_table = "nas"
    group_attribute = "SQL-Group"
    $INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
}
# /etc/freeradius/3.0/clients.conf
# (Otomatis dibaca dari tabel 'nas' jika read_clients = yes)</pre>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title"><i class="bi bi-clock-history"></i> Pengaturan Cron Job (Otomatisasi)</h5>
                <span class="badge bg-success">1 Cron (Laravel Scheduler)</span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Versi Laravel cukup memasang <strong>satu</strong> cron yang menjalankan scheduler tiap menit; semua tugas di bawah dijalankan otomatis dengan pengaman <code>withoutOverlapping</code>.</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered align-middle" style="font-size:.82rem;">
                        <thead class="table-dark"><tr><th>#</th><th>Perintah</th><th>Jadwal</th><th>Fungsi</th></tr></thead>
                        <tbody>
                            @foreach ([
                                ['snet:ont-remote-cleanup', 'Tiap 1 menit', 'Tutup akses port-forward remote ONT yang sudah melewati batas 15 menit'],
                                ['snet:vouchers-expire', 'Tiap 5 menit', 'Aktifkan voucher yang mulai dipakai, putuskan & hapus voucher yang habis masa berlaku'],
                                ['ont:sync', 'Tiap 15 menit', 'Sinkron daftar ONT dari semua server GenieACS'],
                                ['snet:auto-isolir', 'Tiap jam', 'Isolir otomatis pelanggan PPPoE yang lewat jatuh tempo'],
                                ['snet:reminders', 'Setiap hari 08:00', 'Kirim pesan WhatsApp pengingat tagihan PPPoE'],
                                ['snet:backup', 'Setiap hari 02:00', 'Backup database otomatis'],
                            ] as $i => [$cmd, $when, $what])
                            <tr><td>{{ $i + 1 }}</td><td><code>{{ $cmd }}</code></td><td><span class="badge bg-primary">{{ $when }}</span></td><td>{{ $what }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <h6 class="fw-bold mb-2"><i class="bi bi-terminal me-1"></i> Cara Pasang di Terminal (crontab -e):</h6>
                <div class="bg-dark text-light p-3 rounded" style="font-family:monospace;font-size:.78rem;line-height:1.9;">
<pre class="mb-0 text-light"># ── S.NET Manager (Laravel) — Scheduler ──
* * * * * cd {{ base_path() }} &amp;&amp; /usr/bin/php artisan schedule:run &gt;&gt; /dev/null 2&gt;&amp;1</pre>
                </div>
                <div class="alert alert-info mt-3 mb-0" style="font-size:0.82rem;">
                    <i class="bi bi-lightbulb-fill me-2 text-warning"></i><strong>Tips:</strong> Ganti <code>/usr/bin/php</code> dengan path PHP CLI di server Anda (cek: <code>which php</code>). Pengguna <strong>aaPanel</strong> umumnya: <code>/www/server/php/83/bin/php</code>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
