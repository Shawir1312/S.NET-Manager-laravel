@extends('layouts.app')
@section('title', 'Peta Jaringan')
@push('head')
@include('map._styles')
@endpush
@section('page-header')
<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div>
        <h1 class="page-title"><i class="bi bi-map me-2 text-primary"></i>Peta Jaringan</h1>
        <p class="page-subtitle mb-0">Server → ODP → pelanggan. Garis hijau berjalan = terhubung, merah = putus. Diperbarui otomatis tiap 30 detik.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <div class="input-group" style="max-width: 520px;">
            <span class="input-group-text"><i class="bi bi-globe"></i></span>
            <input class="form-control form-control-sm font-monospace" id="publicUrl" value="{{ $publicUrl }}" readonly aria-label="Link peta publik">
            <button class="btn btn-sm btn-outline-primary" type="button" id="copyPublic" title="Salin link"><i class="bi bi-clipboard"></i> Salin link publik</button>
        </div>
        @can('manage-settings')
        <form method="POST" action="{{ route('map.token') }}" onsubmit="return confirm('Ganti link publik? Link lama tidak bisa dipakai lagi.')">@csrf
            <button class="btn btn-sm btn-outline-danger" title="Ganti link publik"><i class="bi bi-arrow-repeat"></i></button></form>
        @endcan
    </div>
</div>
@endsection
@section('content')
@if ($routers->isEmpty())
    <div class="alert alert-warning">Belum ada router / cabang. Tambahkan dulu di menu Router / NAS.</div>
@else
<div class="row g-3">
    <div class="col-12 col-xl-9">
        <div class="card shadow-sm"><div class="card-body p-2">
            <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                <select class="form-select form-select-sm" id="routerSelect" style="max-width: 240px;" aria-label="Cabang">
                    @foreach ($routers as $r)<option value="{{ $r->id }}" @selected($selected?->id === $r->id)>{{ $r->name }}</option>@endforeach
                </select>
                <button class="btn btn-sm btn-primary" type="button" id="btnAddOdp"><i class="bi bi-plus-circle me-1"></i>Tambah ODP</button>
                @can('manage-settings')<button class="btn btn-sm btn-outline-primary" type="button" id="btnPlaceServer"><i class="bi bi-hdd-rack me-1"></i>Letak server</button>@endcan
                <div class="input-group input-group-sm" style="max-width: 320px;">
                    <select class="form-select" id="unplacedSelect" aria-label="Pelanggan belum di peta"><option value="">Pelanggan belum di peta…</option></select>
                    <button class="btn btn-outline-success" type="button" id="btnPlaceCustomer" title="Klik peta untuk menempatkan">Tempatkan</button>
                </div>
                <span class="ms-auto small text-body-secondary" id="mapSummary"></span>
            </div>
            <div id="map" class="nm-map"></div>
            <div class="mt-2">@include('map._legend') <small class="text-body-secondary">Geser marker untuk memindahkan. Klik pelanggan untuk memilih ODP-nya.</small></div>
        </div></div>
    </div>
    <div class="col-12 col-xl-3">
        <div class="card shadow-sm"><div class="card-header fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Bermasalah</div>
            <div class="list-group list-group-flush nm-problems" id="problemList"></div></div>
    </div>
</div>
@endif
@endsection
@push('scripts')
@if ($selected)
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="{{ asset('assets/network-map.js') }}?v={{ filemtime(public_path('assets/network-map.js')) }}"></script>
<script>
(() => {
    const $ = id => document.getElementById(id);
    const routerId = {{ $selected->id }};
    const nm = NetworkMap.init({
        el: 'map', editable: true, routerId, listEl: $('problemList'),
        dataUrl: @json(route('map.data', $selected)),
        urls: {
            odpStore: @json(route('map.odp.store')),
            odpUpdate: @json(route('map.odp.update', '__ID__')),
            odpDestroy: @json(route('map.odp.destroy', '__ID__')),
            customerUpdate: @json(route('map.customer.update', '__ID__')),
            serverUpdate: @json(route('map.server.update', '__ID__')),
        },
        onData: d => {
            const s = d.summary;
            $('mapSummary').innerHTML = `<span class="text-success">● ${s.online} online</span> · <span class="text-danger">● ${s.offline} putus</span> · ${s.isolated} isolir`
                + (s.odp_down ? ` · <span class="text-danger fw-semibold">${s.odp_down} ODP putus</span>` : '')
                + (d.server.lat == null ? ' · <span class="text-warning">letak server belum diatur</span>' : '');
            const sel = $('unplacedSelect'), keep = sel.value;
            sel.innerHTML = `<option value="">Pelanggan belum di peta (${d.unplaced_list.length})…</option>` + d.unplaced_list.map(c => `<option value="${c.id}">${escHtml(c.name)}</option>`).join('');
            sel.value = keep;
        },
    });
    $('routerSelect').addEventListener('change', e => { location.href = @json(route('map.index')) + '?router_id=' + e.target.value; });
    $('btnAddOdp').addEventListener('click', () => nm.pick({ type: 'odp' }));
    $('btnPlaceServer')?.addEventListener('click', () => nm.pick({ type: 'server' }));
    $('btnPlaceCustomer').addEventListener('click', () => {
        const id = $('unplacedSelect').value;
        if (!id) { OntApp.toast('Pilih pelanggan dulu.', 'warning'); return; }
        nm.pick({ type: 'customer', id });
    });
    $('copyPublic').addEventListener('click', () => { navigator.clipboard?.writeText($('publicUrl').value); OntApp.toast('Link peta publik disalin.', 'success'); });
})();
</script>
@endif
@endpush
