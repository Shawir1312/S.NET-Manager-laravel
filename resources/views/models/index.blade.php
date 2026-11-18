@extends('layouts.app')
@section('title', 'Model ONT')
@section('content')
<div class="card">
    <div class="card-header d-flex align-items-center"><span>Model ONT & Mapping Parameter</span>
        <a href="{{ route('models.create') }}" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg"></i> Model baru</a></div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Vendor</th><th>Model</th><th>Product Class</th><th>Firmware</th><th>Status</th><th>Binding</th><th>Mapping (verified)</th><th>ONT</th><th></th></tr></thead>
        <tbody>
        @foreach ($models as $m)
            <tr>
                <td>{{ $m->vendor->label() }}</td>
                <td>{{ $m->model_name }}</td>
                <td class="font-monospace small">{{ $m->product_class }}</td>
                <td class="font-monospace small">{{ $m->firmware_pattern }}</td>
                <td><span class="badge text-bg-{{ $m->status->badge() }}">{{ $m->status->label() }}</span></td>
                <td class="small">{{ $m->capability?->binding_method?->value ?? '-' }}</td>
                <td>{{ $m->parameter_mappings_count }} <span class="text-success">({{ $m->verified_count }})</span></td>
                <td>{{ $m->devices_count }}</td>
                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="{{ route('models.edit', $m) }}">Kelola</a></td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
</div>
<div class="alert alert-info small mt-3 mb-0">
    <i class="bi bi-shield-check"></i> <strong>Aturan keamanan provisioning:</strong> di mode produksi, konfigurasi hanya dikirim bila model berstatus
    <em>Didukung</em> <strong>dan</strong> semua parameter yang dipakai sudah <em>verified</em>. Tandai verified hanya setelah path dan format nilainya
    dicek langsung di ONT asli (menu ONT Devices → Detail → Tampilkan parameter).
</div>
@endsection
