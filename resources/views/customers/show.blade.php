@extends('layouts.app')
@section('title', $customer->full_name)
@section('content')
@include('partials.errors')
@php($tab = request('tab', session('tab', 'info')))
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <div class="h5 mb-0">{{ $customer->full_name }} @include('customers._status', ['status' => $customer->status])</div>
        <div class="small text-body-secondary font-monospace">{{ $customer->pppoe_username }} · {{ $customer->router?->name }}</div>
    </div>
    <a href="{{ route('customers.edit', $customer) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
    @if ($customer->status !== 'active')
        <form method="POST" action="{{ route('customers.status', $customer) }}">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-success btn-sm"><i class="bi bi-unlock"></i> Aktifkan</button></form>
    @else
        <form method="POST" action="{{ route('customers.status', $customer) }}">@csrf<input type="hidden" name="status" value="isolated"><button class="btn btn-warning btn-sm"><i class="bi bi-lock"></i> Isolir</button></form>
    @endif
</div>

<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto" role="tablist">
    @foreach (['info' => ['Info', 'bi-person'], 'ont' => ['Config ONT', 'bi-router'], 'payments' => ['Pembayaran', 'bi-cash-coin']] as $key => [$label, $icon])
        <li class="nav-item"><button class="nav-link text-nowrap {{ $tab === $key ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tab-{{ $key }}" type="button"><i class="bi {{ $icon }}"></i> {{ $label }}</button></li>
    @endforeach
</ul>

<div class="tab-content">
    {{-- ===== INFO ===== --}}
    <div class="tab-pane fade {{ $tab === 'info' ? 'show active' : '' }}" id="tab-info">
        <div class="card"><div class="card-body"><dl class="row mb-0">
            @foreach ([
                'No. WhatsApp' => $customer->phone ?: '-', 'Alamat' => $customer->address ?: '-', 'Paket' => $customer->profile,
                'Tagihan' => $customer->is_free ? 'Gratis' : 'Rp '.number_format($customer->monthly_price, 0, ',', '.').' · jatuh tempo tgl '.$customer->due_day,
                'Username portal' => $customer->portal_username ?: '-', 'SN ONT' => $customer->ont_sn ?: '-', 'VLAN' => $customer->ont_vlan,
                'Nama WiFi' => $customer->ont_wifi_ssid ?: '-',
                'Isolir' => $customer->isolated_at ? $customer->isolated_at->format('d M Y H:i').' — '.$customer->isolated_reason : '-',
                'Catatan' => $customer->notes ?: '-',
            ] as $k => $v)
                <dt class="col-sm-4 col-lg-3 text-body-secondary fw-normal">{{ $k }}</dt><dd class="col-sm-8 col-lg-9">{{ $v }}</dd>
            @endforeach
        </dl></div></div>
        @can('manage-settings')
            <form class="mt-3 confirm-delete" method="POST" action="{{ route('customers.destroy', $customer) }}">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i> Hapus pelanggan</button></form>
        @endcan
    </div>

    {{-- ===== CONFIG ONT ===== --}}
    <div class="tab-pane fade {{ $tab === 'ont' ? 'show active' : '' }}" id="tab-ont">
        @if ($customer->ont_sn === '')
            <div class="alert alert-warning">SN ONT belum diisi. <a href="{{ route('customers.edit', $customer) }}">Isi SN</a> untuk mengonfigurasi ONT.</div>
        @else
            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card h-100"><div class="card-header">ONT</div><div class="card-body">
                        @if ($device)
                            <div class="font-monospace fw-semibold">{{ $device->serial_number }}</div>
                            <div class="small text-body-secondary">{{ $device->vendor?->label() }} {{ $device->product_class }} · inform {{ $device->last_inform_at?->diffForHumans() ?? '-' }}</div>
                            <a class="btn btn-sm btn-outline-secondary mt-2" href="{{ route('devices.show', $device) }}"><i class="bi bi-info-circle"></i> Detail perangkat</a>
                        @else
                            <div class="font-monospace">{{ $customer->ont_sn }}</div>
                            <div class="small text-body-secondary">Belum pernah dibaca dari GenieACS{{ $customer->router?->genieAcsSetting ? ' ('.$customer->router->genieAcsSetting->name.')' : '' }}. Akan dicari saat konfigurasi.</div>
                        @endif
                    </div></div>
                </div>
                @can('configure-ont')
                <div class="col-lg-7">
                    <div class="card h-100"><div class="card-header">Konfigurasi</div><div class="card-body">
                        <form method="POST" action="{{ route('customers.push-ont', $customer) }}" class="row g-2">
                            @csrf
                            <div class="col-md-6"><label class="form-label small">Template PPPoE</label><select class="form-select form-select-sm" name="pppoe_template_id"><option value="">Default router / global</option>
                                @foreach ($templates->where('service_type', \App\Enums\ServiceType::Pppoe) as $t)<option value="{{ $t->id }}">{{ $t->name }}{{ filled($t->payload['hotspot'] ?? null) ? ' + Hotspot' : '' }}</option>@endforeach</select></div>
                            <div class="col-md-6"><label class="form-label small">Template Hotspot (untuk "Hotspot saja")</label><select class="form-select form-select-sm" name="hotspot_template_id"><option value="">Default router / global</option>
                                @foreach ($templates->where('service_type', \App\Enums\ServiceType::Bridge) as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
                            <div class="col-12 d-flex flex-wrap gap-2 mt-2">
                                <button class="btn btn-primary btn-sm" name="service" value="pppoe"><i class="bi bi-lightning"></i> Push PPPoE (+ Hotspot dari template)</button>
                                <button class="btn btn-outline-primary btn-sm" name="service" value="bridge"><i class="bi bi-wifi"></i> Push Hotspot saja</button>
                            </div>
                        </form>
                        <hr>
                        <div class="small text-body-secondary mb-2">Config manual (wizard lengkap: pilih WAN lama/baru, SSID, binding, preview):</div>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('auto-config', ['customer' => $customer->id, 'service' => 'pppoe']) }}"><i class="bi bi-sliders"></i> Manual PPPoE</a>
                            <a class="btn btn-outline-secondary btn-sm" href="{{ route('auto-config', ['customer' => $customer->id, 'service' => 'bridge']) }}"><i class="bi bi-sliders"></i> Manual Hotspot</a>
                        </div>
                    </div></div>
                </div>
                @endcan
                <div class="col-12">
                    <div class="card"><div class="card-header">Riwayat konfigurasi ONT</div>
                        <div class="table-responsive"><table class="table align-middle mb-0 table-stack">
                            <thead><tr><th>Waktu</th><th>Layanan</th><th>Template</th><th>Oleh</th><th>Status</th><th></th></tr></thead>
                            <tbody>
                            @forelse ($jobs as $job)
                                <tr>
                                    <td data-label="Waktu" class="small">{{ $job->created_at->format('d M Y H:i') }}</td>
                                    <td data-label="Layanan">{{ $job->service_type->label() }}</td>
                                    <td data-label="Template">{{ $job->template?->name ?? '-' }}</td>
                                    <td data-label="Oleh">{{ $job->user?->name }}</td>
                                    <td data-label="Status" class="job-status" data-uuid="{{ $job->uuid }}" data-final="{{ $job->status->isFinal() ? 1 : 0 }}">@include('partials.job-status', ['job' => $job])</td>
                                    <td data-label="" class="text-end"><a class="btn btn-sm btn-outline-secondary" href="{{ route('history.show', $job) }}"><i class="bi bi-eye"></i></a></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-body-secondary">Belum ada konfigurasi.</td></tr>
                            @endforelse
                            </tbody>
                        </table></div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ===== PEMBAYARAN ===== --}}
    <div class="tab-pane fade {{ $tab === 'payments' ? 'show active' : '' }}" id="tab-payments">
        @if (Route::has('payments.store') && ! $customer->is_free)
            <form method="POST" action="{{ route('payments.store') }}" class="card card-body mb-3 row g-2 flex-row align-items-end">
                @csrf <input type="hidden" name="customer_id" value="{{ $customer->id }}">
                <div class="col-6 col-md-3"><label class="form-label small">Bulan</label><input class="form-control" type="month" name="period" value="{{ now()->format('Y-m') }}" required></div>
                <div class="col-6 col-md-3"><label class="form-label small">Nominal</label><input class="form-control" type="number" name="amount" min="0" value="{{ $customer->monthly_price }}" required></div>
                <div class="col-6 col-md-3"><label class="form-label small">Metode</label><select class="form-select" name="payment_method"><option value="cash">Tunai</option><option value="transfer">Transfer</option></select></div>
                <div class="col-6 col-md-3"><button class="btn btn-success w-100"><i class="bi bi-cash"></i> Catat bayar</button></div>
            </form>
        @endif
        <div class="card"><div class="table-responsive"><table class="table align-middle mb-0 table-stack">
            <thead><tr><th>Periode</th><th>Nominal</th><th>Metode</th><th>Status</th><th>Dibayar</th></tr></thead>
            <tbody>
            @forelse ($customer->payments as $p)
                <tr>
                    <td data-label="Periode">{{ sprintf('%02d/%d', $p->period_month, $p->period_year) }}</td>
                    <td data-label="Nominal">Rp {{ number_format($p->amount, 0, ',', '.') }}</td>
                    <td data-label="Metode">{{ $p->payment_method }}</td>
                    <td data-label="Status"><span class="badge text-bg-{{ in_array($p->midtrans_status, [null, 'paid'], true) ? 'success' : 'secondary' }}">{{ $p->midtrans_status ?? 'paid' }}</span></td>
                    <td data-label="Dibayar" class="small">{{ $p->paid_at?->format('d M Y H:i') ?? '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-body-secondary">Belum ada pembayaran.</td></tr>
            @endforelse
            </tbody>
        </table></div></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Status job provisioning diperbarui otomatis sampai selesai.
(function () {
    const cells = [...document.querySelectorAll('.job-status[data-final="0"]')];
    if (!cells.length) return;
    const base = @json(url('/api/configuration'));
    const tick = async () => {
        for (const td of cells.filter(c => c.dataset.final === '0')) {
            try {
                const s = await OntApp.api(`${base}/${td.dataset.uuid}/status`);
                td.innerHTML = `<span class="badge text-bg-${s.badge}">${s.label}</span>` + (s.is_simulation ? ' <span class="badge text-bg-warning">SIMULASI</span>' : '');
                if (s.final) td.dataset.final = '1';
            } catch (e) { td.dataset.final = '1'; }
        }
        if (cells.some(c => c.dataset.final === '0')) setTimeout(tick, 4000);
    };
    setTimeout(tick, 2000);
})();
</script>
@endpush
