@extends('layouts.app')
@section('title', 'Template ONT')
@section('content')
@include('partials.errors')
<div class="card">
    <div class="card-header d-flex align-items-center">
        <span>Template Konfigurasi</span>
        @can('manage-templates')
            <a href="{{ route('templates.create') }}" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg"></i> Template baru</a>
        @endcan
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Nama</th><th>Layanan</th><th>VLAN</th><th>Slot</th><th>LAN</th><th>SSID</th><th>Dibuat oleh</th><th></th></tr></thead>
            <tbody>
            @forelse ($templates as $t)
                @php($p = $t->payload)
                <tr>
                    <td>{{ $t->name }} @if ($t->is_default)<span class="badge text-bg-primary">default</span>@endif
                        <div class="small text-body-secondary">{{ $t->description }}</div></td>
                    <td>{{ $t->service_type->label() }}@if (filled($t->payload['hotspot'] ?? null)) <span class="badge text-bg-info">+ Hotspot</span>@endif</td>
                    <td>{{ $p['wan']['vlan_id'] ?? '-' }}</td>
                    <td>{{ strtoupper($p['wan']['slot'] ?? '-') }}</td>
                    <td>{{ collect($p['binding']['lan'] ?? [])->map(fn ($n) => 'LAN '.$n)->join(', ') ?: '-' }}</td>
                    <td>{{ collect($p['binding']['wlan'] ?? [])->map(fn ($n) => 'SSID '.$n)->join(', ') ?: '-' }}</td>
                    <td class="small">{{ $t->creator?->name ?? 'Sistem' }}</td>
                    <td class="text-end text-nowrap">
                        @can('manage-templates')
                            @unless ($t->is_default)
                                <form class="d-inline" method="POST" action="{{ route('templates.default', $t) }}">@csrf<button class="btn btn-sm btn-outline-secondary" title="Jadikan default"><i class="bi bi-star"></i></button></form>
                            @endunless
                            <form class="d-inline" method="POST" action="{{ route('templates.copy', $t) }}">@csrf<button class="btn btn-sm btn-outline-secondary" title="Salin"><i class="bi bi-files"></i></button></form>
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('templates.edit', $t) }}" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form class="d-inline confirm-delete" method="POST" action="{{ route('templates.destroy', $t) }}">@csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" title="Hapus"><i class="bi bi-trash"></i></button></form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-body-secondary text-center py-4">Belum ada template.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="small text-body-secondary mt-2"><i class="bi bi-shield-lock"></i> Template tidak pernah menyimpan password PPPoE maupun password WiFi. Keduanya selalu diisi teknisi di wizard.</p>
@endsection
