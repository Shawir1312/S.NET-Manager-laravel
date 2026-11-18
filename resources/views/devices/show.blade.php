@extends('layouts.app')
@section('title', 'Detail ONT')

@section('content')
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <a href="{{ route('devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Kembali</a>
    <h2 class="h5 mb-0 font-monospace">{{ $device->serial_number }}</h2>
</div>

<div id="searchStatus" class="mb-3" aria-live="polite"></div>
<div id="deviceView"></div>

<div class="card mt-3">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <span>Parameter ONT</span>
        <span class="badge text-bg-secondary" id="paramCount"></span>
        <button class="btn btn-sm btn-outline-primary ms-auto" id="btnLoadParams" type="button"><i class="bi bi-list-ul me-1"></i>Tampilkan parameter</button>
    </div>
    <div class="card-body" id="paramBox" hidden>
        <input class="form-control mb-2" id="paramFilter" placeholder="Filter path atau nilai, mis. WANIPConnection.1 atau VLANID" autocomplete="off">
        <div class="table-responsive param-table-wrap">
            <table class="table table-sm table-striped mb-0 font-monospace small">
                <thead><tr><th>Path</th><th>Nilai</th><th>Tipe</th><th>RW</th></tr></thead>
                <tbody id="paramRows"></tbody>
            </table>
        </div>
        <div class="small text-body-secondary mt-2" id="paramInfo"></div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('assets/ont-device.js') }}"></script>
<script>
    OntDevice.initDetail({
        id: {{ $device->id }},
        showUrl: @json(url('/api/ont')),
        canConfigure: @json(auth()->user()->can('configure-ont')),
    });
</script>
@endpush
