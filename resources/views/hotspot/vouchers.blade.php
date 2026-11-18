@extends('layouts.app')
@section('title', 'Daftar Voucher')
@php
    $badges = ['unused' => '<span class="badge bg-secondary">Belum Dipakai</span>', 'active' => '<span class="badge bg-success">Aktif</span>',
        'expired' => '<span class="badge bg-danger">Kadaluarsa</span>', 'deleted' => '<span class="badge bg-dark">Dihapus</span>'];
    $batchLabel = fn ($b) => \Illuminate\Support\Carbon::parse($b->created_date)->format('d M Y').' — '.($b->profile_name ?: 'Tanpa Profil').' ['.(int) $b->sisa_count.' sisa] · '.$b->batch_id;
@endphp
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title">Daftar Voucher</h1>
        <p class="page-subtitle">
            {{ number_format($vouchers->total()) }} voucher {{ $filter_status === 'unused' ? 'sisa ' : '' }}ditemukan
            @if ($filter_batch) — Batch: <span class="font-mono">{{ $filter_batch }}</span>@endif
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        @if ($filter_batch)
        <a href="{{ route('vouchers.print', ['batch_id' => $filter_batch]) }}" target="_blank" class="btn btn-primary"><i class="bi bi-printer me-1"></i>Cetak Batch Ini</a>
        @endif
        <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal"><i class="bi bi-trash-fill me-1"></i>Hapus Massal</button>
        <a href="{{ route('vouchers.create') }}" class="btn btn-outline-primary"><i class="bi bi-plus-circle me-1"></i>Generate Baru</a>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('vouchers.index') }}" class="row g-2 align-items-end">
            <div class="col-sm-auto">
                <label class="form-label">Status</label>
                <select class="form-select form-select-sm" name="status" id="statusFilter">
                    <option value="" @selected($filter_status === '' && ! $filter_batch)>Semua</option>
                    <option value="all" @selected($filter_status === 'all')>Semua Status</option>
                    <option value="unused" @selected($filter_status === 'unused')>Belum Dipakai (Sisa)</option>
                    <option value="active" @selected($filter_status === 'active')>Aktif</option>
                    <option value="expired" @selected($filter_status === 'expired')>Kadaluarsa</option>
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Router</label>
                <select class="form-select form-select-sm" name="router_id">
                    <option value="">Semua Router</option>
                    @foreach ($all_routers as $r)<option value="{{ $r->id }}" @selected((int) request('router_id') === $r->id)>{{ $r->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Profil</label>
                <select class="form-select form-select-sm" name="profile_id">
                    <option value="">Semua Profil</option>
                    @foreach ($profiles as $p)<option value="{{ $p->id }}" @selected((int) request('profile_id') === $p->id)>{{ $p->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Pilih Batch / Comment</label>
                <select class="form-select form-select-sm" name="batch_id" id="batchSelect" style="max-width: 320px;">
                    <option value="">— Semua Batch —</option>
                    @foreach ($batch_list as $b)<option value="{{ $b->batch_id }}" @selected($filter_batch === $b->batch_id)>{{ $batchLabel($b) }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-auto">
                <label class="form-label">Cari Username</label>
                <input type="text" class="form-control form-control-sm" name="q" value="{{ request('q') }}" placeholder="Username...">
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="{{ route('vouchers.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Action Bar -->
<div id="bulk-bar" class="alert alert-info d-none align-items-center gap-3 mb-3">
    <span><strong id="selected-count">0</strong> voucher dipilih</span>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('vouchers.reactivate') }}" class="d-inline">
            @csrf
            <input type="hidden" name="voucher_ids" id="bulk-reactivate-ids-input">
            <button type="submit" class="btn btn-success btn-sm" data-confirm="Aktifkan kembali voucher yang dipilih? Voucher akan kembali ke status Belum Dipakai dan disinkronkan ke FreeRADIUS.">
                <i class="bi bi-arrow-clockwise me-1"></i>Pulihkan Dipilih
            </button>
        </form>
        <form method="POST" action="{{ route('vouchers.destroy') }}" class="d-inline">
            @csrf @method('DELETE')
            <input type="hidden" name="ids" id="bulk-ids-input">
            <button type="submit" class="btn btn-danger btn-sm" data-confirm="Hapus voucher yang dipilih? Ini akan menghapus dari radcheck/radreply juga.">
                <i class="bi bi-trash me-1"></i>Hapus Dipilih
            </button>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card table-card">
    <div class="table-responsive">
        <table class="table" id="data-table">
            <thead><tr>
                <th><input type="checkbox" id="select-all" class="form-check-input"></th>
                <th>Username</th><th>Password</th><th>Profil</th><th>Router</th><th>Batch</th><th>Status</th><th>Dibuat</th><th>Aksi</th>
            </tr></thead>
            <tbody>
            @forelse ($vouchers as $v)
            <tr>
                <td><input type="checkbox" class="form-check-input row-check" value="{{ $v->id }}"></td>
                <td class="font-mono fw-600" style="font-size:.82rem;">{{ $v->username }}</td>
                <td class="font-mono" style="font-size:.82rem;">{{ $v->password }}</td>
                <td>{{ $v->profile?->name ?? '-' }}</td>
                <td><span class="text-muted" style="font-size:.78rem;">{{ $v->router?->name ?? 'Semua' }}</span></td>
                <td>
                    <a href="{{ route('vouchers.index', ['batch_id' => $v->batch_id, 'status' => 'unused']) }}" class="font-mono text-blue" style="font-size:.72rem;" title="Klik untuk lihat voucher sisa dari batch ini">{{ $v->batch_id ?? '-' }}</a>
                </td>
                <td>
                    {!! $badges[$v->status] ?? '<span class="badge bg-light text-dark">'.e($v->status).'</span>' !!}
                    @if ($v->used_at)
                    <div style="font-size:0.7rem; color:var(--gray-600); margin-top:2px;"><i class="bi bi-box-arrow-in-right"></i> {{ $v->used_at->format('d/m/y H:i') }}</div>
                    @endif
                    @if ($v->expired_at)
                    <div style="font-size:0.7rem; color:var(--red); margin-top:2px;">
                        <i class="bi bi-clock-history"></i> {{ $v->expired_at->format('d/m/y H:i') }}
                        @if ($v->status === 'active')
                            @if ($v->expired_at->isFuture())
                                <br><span class="badge bg-info text-dark mt-1" style="font-size:0.65rem;" title="Sisa Masa Aktif (Validity)"><i class="bi bi-calendar-x"></i> {{ now()->diffForHumans($v->expired_at, \Carbon\CarbonInterface::DIFF_ABSOLUTE, false, 2) }}</span>
                            @else
                                <br><span class="badge bg-danger mt-1" style="font-size:0.65rem;"><i class="bi bi-calendar-x"></i> Habis</span>
                            @endif
                        @endif
                    </div>
                    @endif
                </td>
                <td style="font-size:.75rem;color:var(--gray-500);">{{ $v->created_at?->format('d/m/Y H:i') }}</td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="{{ route('vouchers.print', ['voucher_id' => $v->id]) }}" target="_blank" class="btn btn-sm btn-outline-primary btn-icon" title="Cetak"><i class="bi bi-printer"></i></a>
                        @if ($v->status === 'expired')
                        <form method="POST" action="{{ route('vouchers.reactivate') }}" class="d-inline" onsubmit="return confirm('Aktifkan kembali voucher \'{{ $v->username }}\'? Voucher akan kembali ke status Belum Dipakai dan disinkronkan ke FreeRADIUS.');">
                            @csrf <input type="hidden" name="voucher_id" value="{{ $v->id }}">
                            <button type="submit" class="btn btn-sm btn-outline-success btn-icon" title="Aktifkan Kembali / Pulihkan Voucher"><i class="bi bi-arrow-clockwise"></i></button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('vouchers.destroy') }}" class="d-inline">
                            @csrf @method('DELETE') <input type="hidden" name="ids" value="{{ $v->id }}">
                            <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" data-confirm="Hapus voucher '{{ $v->username }}'? Ini juga akan menghapus dari radcheck/radreply." title="Hapus"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-ticket-perforated display-4 d-block mb-2"></i>Tidak ada voucher ditemukan.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-body py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">Menampilkan {{ $vouchers->count() }} dari {{ number_format($vouchers->total()) }} voucher</small>
        {{ $vouchers->links('pagination::bootstrap-5') }}
    </div>
</div>

<script>
(function() {
    const selectAll = document.getElementById('select-all'), bulkBar = document.getElementById('bulk-bar'), countEl = document.getElementById('selected-count');
    function updateBulk() {
        const checked = [...document.querySelectorAll('.row-check:checked')];
        bulkBar.classList.toggle('d-none', checked.length === 0);
        bulkBar.classList.toggle('d-flex', checked.length > 0);
        countEl.textContent = checked.length;
        const ids = checked.map(cb => cb.value).join(',');
        document.getElementById('bulk-ids-input').value = ids;
        document.getElementById('bulk-reactivate-ids-input').value = ids;
    }
    selectAll?.addEventListener('change', function() { document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked); updateBulk(); });
    document.querySelectorAll('.row-check').forEach(cb => cb.addEventListener('change', updateBulk));
    const batchSel = document.getElementById('batchSelect'), statusSel = document.getElementById('statusFilter');
    batchSel?.addEventListener('change', function() { if (this.value && (statusSel.value === '' || statusSel.value === 'all')) statusSel.value = 'unused'; });
})();
</script>

<!-- Bulk Delete Modal -->
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('vouchers.bulk-destroy') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-trash-fill text-danger me-2"></i>Hapus Massal Voucher</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning"><i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Perhatian!</strong> Fitur ini akan menghapus voucher secara permanen dari database aplikasi dan dari Mikrotik (radcheck & radreply).</div>
                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Profil</label>
                    <select class="form-select" name="profile_id">
                        <option value="">— Pilih Profil (Opsional) —</option>
                        @foreach ($profiles as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Batch ID</label>
                    <select class="form-select" name="batch_id">
                        <option value="">— Kosongkan jika tidak memfilter Batch —</option>
                        @foreach ($batch_list as $b)<option value="{{ $b->batch_id }}">{{ $batchLabel($b) }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Hapus Berdasarkan Status</label>
                    <select class="form-select" name="status">
                        <option value="">— Semua Status —</option>
                        <option value="unused">Belum Dipakai</option><option value="active">Aktif</option><option value="expired">Kadaluarsa</option>
                    </select>
                </div>
                <div class="form-check text-danger mt-4">
                    <input class="form-check-input" type="checkbox" name="confirm_all" value="1" id="confirmAll" required>
                    <label class="form-check-label fw-bold" for="confirmAll">Saya yakin ingin menghapus voucher sesuai kriteria di atas!</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-danger"><i class="bi bi-trash"></i> Eksekusi Hapus</button>
            </div>
        </form>
    </div>
</div>
@endsection
