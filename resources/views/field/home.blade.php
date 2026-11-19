@extends('layouts.field')
@section('title', 'Beranda')
@section('content')
@canany(['operate', 'configure-ont'])
<a href="{{ route('field.add') }}" class="card shadow-sm text-decoration-none mb-4">
    <div class="card-body d-flex align-items-center gap-3 py-4">
        <i class="bi bi-qr-code-scan text-primary" style="font-size: 2.5rem;"></i>
        <div><div class="fw-bold fs-5 text-body">Tambah Pelanggan</div><small class="text-body-secondary">Scan / isi SN → pilih Rumahan (PPPoE) atau Hotspot saja</small></div>
    </div>
</a>
@endcanany

<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i> Setting ONT terakhir saya</div>
    <ul class="list-group list-group-flush">
        @forelse ($jobs as $job)
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                <div class="text-truncate">
                    <div class="font-monospace small">{{ $job->device?->serial_number ?? '-' }}</div>
                    <small class="text-body-secondary">{{ $job->service_type->label() }} · {{ $job->created_at->diffForHumans() }}</small>
                </div>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                    <span class="badge text-bg-{{ $job->status->badge() }}">{{ $job->status->label() }}</span>
                    @if ($job->status !== \App\Enums\JobStatus::Success && $job->status->isFinal() && $job->service_type === \App\Enums\ServiceType::Bridge && $job->device)
                        <a class="btn btn-sm btn-outline-primary" href="{{ route('field.hotspot', ['sn' => $job->device->serial_number]) }}" title="Coba lagi" aria-label="Coba lagi"><i class="bi bi-arrow-repeat"></i></a>
                    @endif
                    @can('rollback', $job)
                        @if ($job->status->isFinal() && ($job->is_simulation ? $job->backups->isNotEmpty() : $job->backups->contains('applied', true)) && ! $job->histories->contains('event', 'rollback'))
                            <button class="btn btn-sm btn-outline-danger js-rollback" data-url="{{ route('api.configuration.rollback', $job) }}" title="Rollback" aria-label="Rollback"><i class="bi bi-arrow-counterclockwise"></i></button>
                        @endif
                    @endcan
                    @can('view', $job)<a class="btn btn-sm btn-outline-secondary" href="{{ route('history.show', $job) }}" title="Detail" aria-label="Detail"><i class="bi bi-chevron-right"></i></a>@endcan
                </div>
            </li>
        @empty
            <li class="list-group-item text-body-secondary">Belum ada.</li>
        @endforelse
    </ul>
</div>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.js-rollback').forEach(b => b.addEventListener('click', async () => {
    if (!b.dataset.confirm) { b.dataset.confirm = '1'; b.innerHTML = 'Yakin?'; return; }
    b.disabled = true;
    try { const r = await OntApp.api(b.dataset.url, { method: 'POST' }); OntApp.toast(r.message, r.status === 'complete' || r.status === 'simulated' ? 'success' : 'warning'); setTimeout(() => location.reload(), 1200); }
    catch (err) { OntApp.toast(err.message, 'danger'); b.disabled = false; }
}));
</script>
@endpush
