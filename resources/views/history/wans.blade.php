@extends('layouts.app')
@section('title', 'WAN Management')
@section('content')
<div class="card">
    <div class="card-header d-flex flex-wrap gap-2 align-items-center">
        <span>Riwayat konfigurasi WAN</span>
        <form class="ms-auto d-flex gap-2"><input class="form-control form-control-sm" name="q" value="{{ $q }}" placeholder="SN atau VLAN"><button class="btn btn-sm btn-secondary">Cari</button></form>
    </div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
        <thead><tr><th>Tanggal</th><th>SN</th><th>WAN</th><th>Mode</th><th>VLAN</th><th>Service</th><th>Binding</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($rows as $w)
            <tr>
                <td class="text-nowrap">{{ $w->job->created_at->format('d/m/Y H:i') }}</td>
                <td class="font-monospace">{{ $w->device?->serial_number }}</td>
                <td>{{ $w->wan_name }}<div class="font-monospace text-body-secondary">{{ $w->instance_path ? str_replace('InternetGatewayDevice.WANDevice.1.', '', $w->instance_path) : '(baru)' }}</div></td>
                <td>{{ $w->connection_type }}</td>
                <td>{{ $w->vlan_id }}</td>
                <td>{{ $w->service_list }}</td>
                <td>{{ $w->bindings->map(fn ($b) => strtoupper($b->interface_type).$b->interface_index)->join(', ') ?: '-' }}</td>
                <td>@include('partials.job-status', ['job' => $w->job]) <a href="{{ route('history.show', $w->job) }}" class="ms-1">detail</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-body-secondary py-4">Belum ada konfigurasi WAN.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if ($rows->hasPages())<div class="card-footer">{{ $rows->links('pagination::bootstrap-5') }}</div>@endif
</div>
<p class="small text-body-secondary mt-2">Untuk membuat atau mengubah WAN, gunakan <a href="{{ route('auto-config') }}">Auto Configuration</a>.</p>
@endsection
