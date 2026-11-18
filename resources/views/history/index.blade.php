@extends('layouts.app')
@section('title', 'Riwayat Config ONT')
@section('content')
<div class="card">
    <div class="card-body border-bottom">
        <form class="row g-2">
            <div class="col-md-4"><input class="form-control" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Cari SN"></div>
            <div class="col-6 col-md-2"><select class="form-select" name="status"><option value="">Semua status</option>
                @foreach (\App\Enums\JobStatus::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['status'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><select class="form-select" name="service_type"><option value="">Semua layanan</option>
                @foreach (\App\Enums\ServiceType::cases() as $s)<option value="{{ $s->value }}" @selected(($filters['service_type'] ?? '') === $s->value)>{{ $s->label() }}</option>@endforeach</select></div>
            <div class="col-6 col-md-2"><select class="form-select" name="mode"><option value="">Semua mode</option>
                <option value="production" @selected(($filters['mode'] ?? '') === 'production')>Produksi</option>
                <option value="simulation" @selected(($filters['mode'] ?? '') === 'simulation')>Simulasi</option></select></div>
            <div class="col-6 col-md-2 d-grid"><button class="btn btn-secondary"><i class="bi bi-funnel"></i> Filter</button></div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Tanggal</th><th>SN</th><th>Vendor / Model</th><th>Layanan</th><th>VLAN</th><th>Teknisi</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse ($jobs as $job)
                <tr>
                    <td class="small text-nowrap">{{ $job->created_at->format('d/m/Y H:i') }}</td>
                    <td class="font-monospace">{{ $job->device?->serial_number }}</td>
                    <td>{{ $job->device?->vendor?->label() }} {{ $job->device?->product_class }}</td>
                    <td>{{ $job->service_type->label() }}</td>
                    <td>{{ $job->wanConfigurations->first()?->vlan_id ?? '-' }}</td>
                    <td class="small">{{ $job->user?->name ?? '-' }}</td>
                    <td>@include('partials.job-status')</td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('history.show', $job) }}">Detail</a></td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-body-secondary py-4">Belum ada riwayat konfigurasi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($jobs->hasPages())<div class="card-footer">{{ $jobs->links('pagination::bootstrap-5') }}</div>@endif
</div>
@endsection
