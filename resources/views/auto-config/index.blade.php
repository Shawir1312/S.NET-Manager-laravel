@extends('layouts.app')
@section('title', request('service') === 'bridge' ? 'Setting Hotspot ONT' : 'Config ONT Manual')
@section('subtitle', 'Konfigurasi WAN, VLAN, SSID & binding ONT lewat GenieACS — preview, backup, verifikasi dan rollback otomatis')

@php
    $steps = [
        [1, 'Cari ONT'], [2, 'Info & Layanan'], [3, 'WAN & VLAN'], [4, 'SSID & WiFi'], [5, 'Binding'],
        [6, 'Preview'], [7, 'Apply'], [8, 'Monitor'], [9, 'Verifikasi'],
    ];
@endphp

@section('content')
@if ($prefill && $prefill['customer'])
    <div class="alert alert-primary d-flex gap-2 align-items-center">
        <i class="bi bi-person-badge fs-5"></i>
        <div>Config ONT manual untuk <strong>{{ $prefill['customer'] }}</strong> — {{ $prefill['service_type'] === 'pppoe' ? 'PPPoE' : 'Hotspot' }}. Form diisi dari data pelanggan & template; periksa lalu Preview sebelum Apply.</div>
    </div>
@endif
<ol class="wizard-steps mb-4" aria-label="Tahapan konfigurasi">
    @foreach ($steps as [$no, $label])
        <li class="wizard-step {{ $no === 1 ? 'active' : 'locked' }}" data-step="{{ $no }}">
            <span class="wizard-num">{{ $no }}</span><span class="wizard-label">{{ $label }}</span>
        </li>
    @endforeach
</ol>

<div class="card mb-3">
    <div class="card-body">
        <form id="searchForm" class="row g-2 align-items-end" novalidate>
            <div class="col-md-8 col-lg-6">
                <label for="sn" class="form-label fw-semibold">Input Serial Number</label>
                <input id="sn" name="serial_number" class="form-control form-control-lg font-monospace text-uppercase"
                       placeholder="mis. FHTT9D34CF80 / ZTEG12345678 / HWTC9C31B89F" autocomplete="off" maxlength="64" required autofocus>
                <div class="invalid-feedback" id="snError"></div>
            </div>
            <div class="col-md-4 col-lg-3">
                <button type="submit" class="btn btn-primary btn-lg w-100" id="btnSearch">
                    <i class="bi bi-search me-1"></i> SEARCH ONT
                </button>
            </div>
        </form>
    </div>
</div>

<div id="searchStatus" class="mb-3" aria-live="polite"></div>
<div id="deviceChooser" class="mb-3" hidden></div>
<div id="wizardStart" class="mb-3"></div>
<div id="deviceView" hidden></div>
<div id="wizard"></div>
@endsection

@push('scripts')
<script src="{{ asset('assets/ont-device.js') }}"></script>
<script>
    window.WIZARD_CFG = {
        apiOnt: @json(url('/api/ont')),
        apiConfig: @json(url('/api/configuration')),
        configureUrl: @json(route('api.ont.configure')),
        templatesUrl: @json(route('api.templates')),
        historyUrl: @json(url('/history')),
        canConfigure: @json(auth()->user()->can('configure-ont')),
        prefill: @json($prefill),
    };
    OntDevice.initAutoConfig({
        searchUrl: @json(route('api.ont.search')),
        showUrl: @json(url('/api/ont')),
        deviceUrl: @json(url('/devices')),
        canConfigure: @json(auth()->user()->can('configure-ont')),
        prefill: @json($prefill),
    });
</script>
@can('configure-ont')
<script src="{{ asset('assets/wizard.js') }}"></script>
@endcan
@endpush
