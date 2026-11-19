@extends('layouts.field')
@section('title', 'Beranda')
@section('content')
<div class="row g-3 mb-4">
    @can('configure-ont')
    <div class="col-12 col-md-6">
        <a href="{{ route('field.hotspot') }}" class="card shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3 py-4">
                <i class="bi bi-qr-code-scan text-primary" style="font-size: 2.5rem;"></i>
                <div><div class="fw-bold fs-5 text-body">Setting ONT Hotspot</div><small class="text-body-secondary">Scan SN → isi nama → template hotspot</small></div>
            </div>
        </a>
    </div>
    @endcan
    @can('operate')
    <div class="col-12 col-md-6">
        <a href="{{ route('field.customers.create') }}" class="card shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3 py-4">
                <i class="bi bi-house-add text-success" style="font-size: 2.5rem;"></i>
                <div><div class="fw-bold fs-5 text-body">Tambah Pelanggan Rumahan</div><small class="text-body-secondary">PPPoE + konfigurasi ONT otomatis</small></div>
            </div>
        </a>
    </div>
    @endcan
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-clock-history me-1"></i> Setting ONT terakhir saya</div>
    <ul class="list-group list-group-flush">
        @forelse ($jobs as $job)
            <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                <div class="text-truncate">
                    <div class="font-monospace small">{{ $job->device?->serial_number ?? '-' }}</div>
                    <small class="text-body-secondary">{{ $job->service_type->label() }} · {{ $job->created_at->diffForHumans() }}</small>
                </div>
                <span class="badge text-bg-{{ $job->status->badge() }}">{{ $job->status->label() }}</span>
            </li>
        @empty
            <li class="list-group-item text-body-secondary">Belum ada.</li>
        @endforelse
    </ul>
</div>
@endsection
