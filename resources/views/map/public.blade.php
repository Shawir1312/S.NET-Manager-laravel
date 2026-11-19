<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Peta Jaringan — {{ config('snet.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('snet/logo.png') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    @include('map._styles')
    <style>
        body { background: #f1f5f9; }
        .pm-top { background: #1d4ed8; color: #fff; padding: 10px 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .pm-top img { height: 30px; background: #fff; padding: 2px; border-radius: 4px; }
        .nm-map { height: calc(100vh - 150px); }
        .branch-card { cursor: pointer; transition: transform .1s; }
        .branch-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
<header class="pm-top">
    <img src="{{ asset('snet/logo.png') }}" alt="Logo">
    <div class="fw-bold me-auto">{{ config('snet.name') }} — Peta Jaringan</div>
    <select class="form-select form-select-sm" id="branchSelect" style="max-width: 240px; display:none;" aria-label="Pilih cabang">
        @foreach ($routers as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach
    </select>
</header>

<main class="p-3">
    {{-- Langkah 1: pilih cabang --}}
    <div id="branchPicker" class="mx-auto" style="max-width: 720px;">
        <h1 class="h5 fw-bold mb-3">Pilih cabang</h1>
        <div class="row g-3">
            @forelse ($routers as $r)
                <div class="col-12 col-sm-6"><button type="button" class="card shadow-sm w-100 text-start branch-card border-0" data-branch="{{ $r->id }}">
                    <div class="card-body d-flex align-items-center gap-3"><i class="bi bi-hdd-rack-fill fs-2 text-primary"></i>
                        <div><div class="fw-semibold">{{ $r->name }}</div><small class="text-body-secondary">Lihat peta server → ODP → pelanggan</small></div></div>
                </button></div>
            @empty
                <div class="col-12"><div class="alert alert-warning">Belum ada cabang aktif.</div></div>
            @endforelse
        </div>
    </div>

    {{-- Langkah 2: peta cabang --}}
    <div id="mapView" class="row g-3" style="display:none;">
        <div class="col-12 col-lg-9">
            <div class="card shadow-sm border-0"><div class="card-body p-2">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="btnBack"><i class="bi bi-arrow-left"></i> Cabang</button>
                    <strong id="branchName"></strong>
                    <span class="ms-auto small" id="mapSummary"></span>
                </div>
                <div id="map" class="nm-map"></div>
                <div class="mt-2">@include('map._legend')</div>
            </div></div>
        </div>
        <div class="col-12 col-lg-3">
            <div class="card shadow-sm border-0"><div class="card-header fw-semibold bg-white"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Pelanggan bermasalah</div>
                <div class="list-group list-group-flush nm-problems" id="problemList"></div></div>
            <div class="small text-body-secondary mt-2" id="updatedAt"></div>
        </div>
    </div>
</main>

<div id="toast-container"></div>
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastArea"></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('assets/app.js') }}"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="{{ asset('assets/network-map.js') }}?v={{ filemtime(public_path('assets/network-map.js')) }}"></script>
<script>
(() => {
    const $ = id => document.getElementById(id);
    const DATA_URL = @json(route('public-map.data', [$token, '__R__']));
    let current = null;

    function openBranch(id) {
        const name = $('branchSelect').querySelector(`option[value="${id}"]`)?.textContent || '';
        $('branchPicker').style.display = 'none';
        $('mapView').style.display = '';
        $('branchSelect').style.display = '';
        $('branchSelect').value = id;
        $('branchName').textContent = name;
        history.replaceState(null, '', '#cabang-' + id);
        // Peta baru per cabang (Leaflet tidak bisa dipasang dua kali di elemen yang sama).
        if (current) { current.map.remove(); $('map').replaceWith(Object.assign(document.createElement('div'), { id: 'map', className: 'nm-map' })); }
        current = NetworkMap.init({
            el: 'map', editable: false, listEl: $('problemList'), dataUrl: DATA_URL.replace('__R__', id), urls: {},
            onData: d => {
                const s = d.summary;
                $('mapSummary').innerHTML = `<span class="text-success">● ${s.online} terhubung</span> · <span class="text-danger">● ${s.offline} putus</span>`
                    + (s.odp_down ? ` · <span class="text-danger fw-semibold">${s.odp_down} ODP putus</span>` : '');
                $('updatedAt').textContent = 'Diperbarui ' + new Date(d.updated_at).toLocaleTimeString('id-ID') + ' · otomatis tiap 30 detik';
            },
        });
    }

    document.querySelectorAll('[data-branch]').forEach(b => b.addEventListener('click', () => openBranch(b.dataset.branch)));
    $('branchSelect').addEventListener('change', e => openBranch(e.target.value));
    $('btnBack').addEventListener('click', () => { $('mapView').style.display = 'none'; $('branchPicker').style.display = ''; $('branchSelect').style.display = 'none'; history.replaceState(null, '', location.pathname); });
    const hash = location.hash.match(/^#cabang-(\d+)$/);
    if (hash) openBranch(hash[1]);
    else if (document.querySelectorAll('[data-branch]').length === 1) openBranch(document.querySelector('[data-branch]').dataset.branch);
})();
</script>
</body>
</html>
