@extends('layouts.app')
@section('title', 'Daftar ONT')

@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <span>Daftar ONT <span class="text-body-secondary fw-normal">({{ number_format($devices->total()) }})</span></span>
        @can('configure-ont')
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="btnSync">
                <i class="bi bi-arrow-repeat me-1"></i> Sinkronkan dari GenieACS
            </button>
        @endcan
    </div>
    <div class="card-body border-bottom">
        <form class="row g-2" method="GET" action="{{ route('devices.index') }}">
            <div class="col-md-5">
                <input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari SN, model, atau IP">
            </div>
            <div class="col-6 col-md-3">
                <select class="form-select" name="vendor" aria-label="Filter vendor">
                    <option value="">Semua vendor</option>
                    @foreach ($vendors as $v)
                        <option value="{{ $v->value }}" @selected(($filters['vendor'] ?? '') === $v->value)>{{ $v->label() }}</option>
                    @endforeach
                    <option value="other" @selected(($filters['vendor'] ?? '') === 'other')>Lainnya</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select class="form-select" name="status" aria-label="Filter status">
                    <option value="">Semua status</option>
                    <option value="online" @selected(($filters['status'] ?? '') === 'online')>Online</option>
                    <option value="offline" @selected(($filters['status'] ?? '') === 'offline')>Offline</option>
                </select>
            </div>
            <div class="col-md-2 d-grid"><button class="btn btn-secondary" type="submit"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
            <tr><th>Serial Number</th><th>Vendor</th><th>Model</th><th>Firmware</th><th>IP</th><th>Inform Terakhir</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse ($devices as $d)
                <tr>
                    <td class="font-monospace">{{ $d->serial_number }}</td>
                    <td>{{ $d->vendor?->label() ?? ($d->manufacturer ?: '-') }}</td>
                    <td>
                        {{ $d->product_class }}
                        @if ($d->ontModel)
                            <span class="badge text-bg-{{ $d->ontModel->status->badge() }}">{{ $d->ontModel->status->label() }}</span>
                        @else
                            <span class="badge text-bg-secondary">Belum terdaftar</span>
                        @endif
                    </td>
                    <td class="small">{{ $d->software_version ?? '-' }}</td>
                    <td class="small font-monospace">{{ $d->ip_address ?? '-' }}</td>
                    <td class="small text-nowrap">{{ $d->last_inform_at?->diffForHumans() ?? '-' }}</td>
                    <td>
                        @if ($d->informedRecently())
                            <span class="badge text-bg-success">Inform &lt; {{ (int) (config('ontconfig.online_threshold_seconds') / 60) }} mnt</span>
                        @else
                            <span class="badge text-bg-danger">Tidak inform</span>
                        @endif
                    </td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('devices.show', $d) }}">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-body-secondary py-4 text-center">
                    Belum ada ONT. @can('configure-ont') Klik <strong>Sinkronkan dari GenieACS</strong>, atau cari lewat Auto Configuration. @endcan
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($devices->hasPages())
        <div class="card-footer">{{ $devices->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
<p class="small text-body-secondary mt-2"><i class="bi bi-info-circle"></i> Status di tabel berasal dari inform terakhir (cache). Status online sebenarnya dicek langsung di halaman detail / Auto Configuration.</p>
@endsection

@can('configure-ont')
@push('scripts')
<script>
    document.getElementById('btnSync')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menyinkronkan…';
        try {
            const r = await OntApp.api(@json(route('api.ont.sync')), { method: 'POST' });
            OntApp.toast(r.message, 'success');
            setTimeout(() => location.reload(), 800);
        } catch (err) {
            OntApp.toast(err.message, 'danger');
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> Sinkronkan dari GenieACS';
        }
    });
</script>
@endpush
@endcan
