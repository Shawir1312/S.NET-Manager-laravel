@extends('layouts.app')
@section('title', 'Detail Konfigurasi')
@section('content')
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <a href="{{ route('history.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h2 class="h5 mb-0 font-monospace">{{ $job->device->serial_number }}</h2>
    @include('partials.job-status')
    @can('rollback', $job)
        @if ($job->status->isFinal() && ($job->is_simulation ? $job->backups->isNotEmpty() : $job->backups->contains('applied', true)) && ! $job->histories->contains('event', 'rollback'))
            <button class="btn btn-sm btn-outline-danger ms-auto" id="btnRollback" data-url="{{ route('api.configuration.rollback', $job) }}"><i class="bi bi-arrow-counterclockwise"></i> Rollback</button>
        @endif
    @endcan
</div>
@if ($job->error_message)<div class="alert alert-{{ $job->status->value === 'partial' ? 'warning' : 'danger' }}">{{ $job->error_message }} <span class="small">({{ $job->error_code }})</span></div>@endif

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3"><div class="card-header">Ringkasan</div>
            <table class="table table-sm mb-0 small"><tbody>
                <tr><th class="fw-normal text-body-secondary">Vendor / Model</th><td>{{ $job->device->vendor?->label() }} {{ $job->device->product_class }}</td></tr>
                <tr><th class="fw-normal text-body-secondary">Layanan</th><td>{{ $job->service_type->label() }}</td></tr>
                <tr><th class="fw-normal text-body-secondary">Teknisi</th><td>{{ $job->user?->name }}</td></tr>
                <tr><th class="fw-normal text-body-secondary">Template</th><td>{{ $job->template?->name ?? '-' }}</td></tr>
                <tr><th class="fw-normal text-body-secondary">Dibuat</th><td>{{ $job->created_at->format('d/m/Y H:i:s') }}</td></tr>
                <tr><th class="fw-normal text-body-secondary">Selesai</th><td>{{ $job->finished_at?->format('d/m/Y H:i:s') ?? '-' }}</td></tr>
                @foreach ($job->wanConfigurations as $w)
                    <tr><th class="fw-normal text-body-secondary">WAN</th><td>{{ $w->wan_name }} · {{ $w->connection_type }} · VLAN {{ $w->vlan_id }}{{ $w->vlan_priority !== null ? ' (prio '.$w->vlan_priority.')' : '' }} · {{ $w->service_list }}
                        <div class="font-monospace">{{ $w->instance_path ?? ($w->created_new ? '(WAN baru)' : '-') }}</div>
                        @if ($w->pppoe_username)<div>PPPoE: {{ $w->pppoe_username }}</div>@endif
                        <div>Binding: {{ $w->bindings->map(fn ($b) => strtoupper($b->interface_type).' '.$b->interface_index)->join(', ') ?: '-' }}</div></td></tr>
                @endforeach
                @foreach ($job->ssidConfigurations as $s)
                    <tr><th class="fw-normal text-body-secondary">SSID {{ $s->ssid_index }}</th><td>{{ $s->ssid_name ?? '(tidak diubah)' }} · {{ $s->security_mode ?? '-' }} {{ $s->enabled === false ? '· nonaktif' : '' }}</td></tr>
                @endforeach
            </tbody></table>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card mb-3"><div class="card-header">Timeline</div><div class="card-body">
            <ul class="timeline list-unstyled mb-0">
                @foreach ($job->histories as $h)
                    <li class="timeline-item tl-{{ $h->status }}"><span class="small text-body-secondary me-2">{{ $h->created_at?->format('H:i:s') }}</span>{{ $h->message }}</li>
                @endforeach
            </ul>
        </div></div>
    </div>
</div>

<div class="card">
    <div class="card-header">Detail Perubahan Parameter (backup)</div>
    <div class="table-responsive"><table class="table table-sm small mb-0">
        <thead><tr><th>Parameter</th><th>Sebelum</th><th>Sesudah</th><th>Rollback</th></tr></thead>
        <tbody>
        @forelse ($job->backups as $b)
            <tr>
                <td class="font-monospace text-break">{{ str_replace('InternetGatewayDevice.', '', $b->parameter_path) }}</td>
                <td>{{ $b->existed_before ? $b->displayValue('old') : '(belum ada)' }}</td>
                <td>{{ $b->new_value === '__created_object__' ? 'objek baru dibuat' : $b->displayValue('new') }}</td>
                <td>{{ $b->restore_status ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-body-secondary">Tidak ada data backup.</td></tr>
        @endforelse
        </tbody>
    </table></div>
</div>
@endsection
@push('scripts')
<script>
document.getElementById('btnRollback')?.addEventListener('click', async (e) => {
    const b = e.currentTarget;
    if (!b.dataset.confirm) { b.dataset.confirm = '1'; b.textContent = 'Klik lagi untuk konfirmasi rollback'; return; }
    b.disabled = true;
    try { const r = await OntApp.api(b.dataset.url, { method: 'POST' }); OntApp.toast(r.message, r.status === 'complete' || r.status === 'simulated' ? 'success' : 'warning'); setTimeout(() => location.reload(), 1200); }
    catch (err) { OntApp.toast(err.message, 'danger'); b.disabled = false; }
});
</script>
@endpush
