@extends('layouts.app')
@section('title', 'Port Forwarding — Remote Access MikroTik')
@section('page-header')
<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div>
        <a href="{{ route('wireguard.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Router</a>
        <h1 class="page-title mt-1"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Port Forwarding (Remote Access)</h1>
        <p class="page-subtitle mb-0">Buka akses Winbox, Webfig, dan API router MikroTik di balik NAT/CGNAT via IP Publik VPS</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="wgTool('sync_firewall', {}, true).then(() => { alert('✓ Aturan firewall iptables berhasil disinkronkan!'); location.reload(); }).catch(e => alert('Error sync firewall: ' + e))"><i class="bi bi-arrow-repeat me-1"></i> Sinkronkan Firewall</button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addForwardModal"><i class="bi bi-plus-circle-fill me-1"></i> Tambah Port Forwarding</button>
    </div>
</div>
@endsection
@section('content')
@include('wireguard._tabs')
@include('partials.errors')
<div class="alert alert-info border-0 shadow-sm mb-4 d-flex align-items-center gap-3">
    <i class="bi bi-info-circle-fill fs-3 text-info"></i>
    <div class="small"><strong>Cara Kerja Remote Access:</strong> Port publik pada VPS Anda (misal: <code>{{ $vpsHost }}:8292</code>) akan otomatis diteruskan secara transparan melalui tunnel WireGuard ke port lokal MikroTik (misal: <code>10.66.66.2:8291</code>). Anda bisa membuka Winbox dari mana saja!</div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <span class="fw-bold fs-6">Daftar Port Forwarding Aktif</span>
            <span class="badge bg-light text-dark border">{{ $forwards->count() }} Aturan</span>
        </div>
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="tab" value="forwards">
            <label class="small text-muted text-nowrap">Filter Router:</label>
            <select name="router_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="0">Semua Router</option>
                @foreach ($routers as $r)<option value="{{ $r->id }}" @selected($selRouterId === $r->id)>{{ $r->name }} ({{ $r->tunnel_ip }})</option>@endforeach
            </select>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Router Target</th><th>Port Publik VPS</th><th>Target IP:Port</th><th>Protokol</th><th>Akses Remote Cepat</th><th class="text-end" style="width: 100px;">Aksi</th></tr></thead>
            <tbody>
            @forelse ($forwards as $pf)
                <tr>
                    <td><a href="{{ route('wireguard.show', $pf->router_id) }}" class="fw-bold text-decoration-none text-dark">{{ $pf->router?->name }}</a></td>
                    <td><span class="badge bg-primary fs-7 font-monospace">{{ $vpsHost }}:{{ $pf->public_port }}</span></td>
                    <td><code class="text-dark">{{ $pf->target_ip ?: $pf->router?->tunnel_ip }}:{{ $pf->target_port }}</code></td>
                    <td><span class="badge bg-secondary text-uppercase">{{ $pf->protocol }}</span></td>
                    <td>@include('wireguard._remote')</td>
                    <td class="text-end">
                        <form method="POST" action="{{ route('wireguard.forwards.destroy', $pf) }}" onsubmit="return confirm('Apakah Anda yakin ingin menghapus port forwarding port {{ $pf->public_port }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center py-5 text-muted"><i class="bi bi-arrow-left-right fs-1 d-block mb-2 text-secondary"></i>Belum ada aturan Port Forwarding yang dibuat.
                    <div class="mt-2"><button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addForwardModal"><i class="bi bi-plus-circle me-1"></i> Tambah Port Forwarding Sekarang</button></div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addForwardModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('wireguard.forwards.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-plus-circle text-primary me-2"></i>Tambah Port Forwarding</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Pilih Router MikroTik <span class="text-danger">*</span></label>
                        <select name="router_id" id="modal_router_id" class="form-select" required onchange="updateDefaultTargetIp()">
                            <option value="">-- Pilih Router --</option>
                            @foreach ($routers as $r)<option value="{{ $r->id }}" data-ip="{{ $r->tunnel_ip }}" @selected($selRouterId === $r->id)>{{ $r->name }} ({{ $r->tunnel_ip }})</option>@endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Preset Port Layanan</label>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(8291, 'tcp')">Winbox (8291)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(80, 'tcp')">Webfig (80)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(8728, 'tcp')">MikroTik API (8728)</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="applyPreset(22, 'tcp')">SSH (22)</button>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Port Publik VPS <span class="text-danger">*</span></label>
                            <input type="number" name="public_port" id="modal_pub_port" class="form-control font-monospace" placeholder="Misal: 8292" min="1025" max="65535" required>
                            <div class="form-text">Port yang dibuka di VPS</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Target Port Router <span class="text-danger">*</span></label>
                            <input type="number" name="target_port" id="modal_target_port" class="form-control font-monospace" placeholder="Misal: 8291" min="1" max="65535" required>
                            <div class="form-text">Port lokal di MikroTik</div>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-8">
                            <label class="form-label fw-semibold">Target IP Router</label>
                            <input type="text" name="target_ip" id="modal_target_ip" class="form-control font-monospace" placeholder="Otomatis IP Tunnel">
                            <div class="form-text">Kosongkan untuk pakai IP tunnel router</div>
                        </div>
                        <div class="col-4">
                            <label class="form-label fw-semibold">Protokol</label>
                            <select name="protocol" id="modal_protocol" class="form-select"><option value="tcp">TCP</option><option value="udp">UDP</option></select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-circle-fill me-1"></i> Simpan &amp; Terapkan NAT</button>
                </div>
            </form>
        </div>
    </div>
</div>
@include('wireguard._tools')
<script>
function applyPreset(port, proto) {
    document.getElementById('modal_target_port').value = port;
    document.getElementById('modal_protocol').value = proto;
    if (!document.getElementById('modal_pub_port').value) {
        document.getElementById('modal_pub_port').value = port === 8291 ? 8292 : (port === 80 ? 8080 : port + 10000);
    }
}
function updateDefaultTargetIp() {
    const sel = document.getElementById('modal_router_id'), opt = sel.options[sel.selectedIndex];
    if (opt && opt.dataset.ip) document.getElementById('modal_target_ip').placeholder = opt.dataset.ip;
}
updateDefaultTargetIp();
</script>
@endsection
