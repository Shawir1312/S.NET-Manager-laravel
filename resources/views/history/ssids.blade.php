@extends('layouts.app')
@section('title', 'WiFi / SSID')
@section('content')
<div class="card">
    <div class="card-header">Riwayat perubahan WiFi / SSID</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 small">
        <thead><tr><th>Tanggal</th><th>SN</th><th>SSID</th><th>Nama</th><th>Keamanan</th><th>Aktif</th><th>Hidden</th><th>Status</th></tr></thead>
        <tbody>
        @forelse ($rows as $s)
            <tr>
                <td class="text-nowrap">{{ $s->job->created_at->format('d/m/Y H:i') }}</td>
                <td class="font-monospace">{{ $s->job->device?->serial_number }}</td>
                <td>SSID {{ $s->ssid_index }}</td>
                <td>{{ $s->ssid_name ?? '(tidak diubah)' }}</td>
                <td>{{ $s->security_mode ?? '-' }}</td>
                <td>{{ $s->enabled === null ? '-' : ($s->enabled ? 'Ya' : 'Tidak') }}</td>
                <td>{{ $s->hidden === null ? '-' : ($s->hidden ? 'Ya' : 'Tidak') }}</td>
                <td>@include('partials.job-status', ['job' => $s->job]) <a href="{{ route('history.show', $s->job) }}" class="ms-1">detail</a></td>
            </tr>
        @empty
            <tr><td colspan="8" class="text-center text-body-secondary py-4">Belum ada perubahan SSID.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    @if ($rows->hasPages())<div class="card-footer">{{ $rows->links('pagination::bootstrap-5') }}</div>@endif
</div>
<p class="small text-body-secondary mt-2">Password WiFi tidak pernah disimpan maupun ditampilkan. Perubahan SSID dilakukan lewat <a href="{{ route('auto-config') }}">Auto Configuration</a>.</p>
@endsection
