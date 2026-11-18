@extends('layouts.app')
@section('title', 'Riwayat Pembayaran PPPoE')
@php
    $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
    $months = \App\Services\Pppoe\BillingService::MONTHS;
    $methodBadges = [
        'cash' => '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-cash me-1"></i>Tunai</span>',
        'midtrans' => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle"><i class="bi bi-credit-card me-1"></i>Midtrans</span>',
        'tripay' => '<span class="badge bg-warning-subtle text-dark border border-warning-subtle"><i class="bi bi-lightning-charge-fill me-1 text-warning"></i>Tripay</span>',
        'transfer' => '<span class="badge bg-info-subtle text-info border border-info-subtle"><i class="bi bi-bank me-1"></i>Transfer</span>',
        'qris' => '<span class="badge bg-light text-dark border"><i class="bi bi-qr-code me-1"></i>QRIS</span>',
    ];
@endphp
@section('page-header')
<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-wallet2 text-primary me-2"></i>Riwayat Pembayaran PPPoE</h1>
        <p class="page-subtitle">Rekapitulasi transaksi penagihan dan pembayaran broadband pelanggan</p>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPayGlobal"><i class="bi bi-cash-stack me-1"></i> Catat Bayar Kasir</button>
        <a href="{{ route('payments.export', request()->query()) }}" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i> Export CSV</a>
    </div>
</div>
@endsection
@section('content')
@include('partials.errors')
<div class="row g-3 mb-4">
    <div class="col-12 col-md-4"><div class="stat-card green h-100"><div class="stat-icon"><i class="bi bi-cash-stack"></i></div><div class="stat-value">{{ $rp($stats['total_paid']) }}</div><div class="stat-label">Total Terbayar (Filter)</div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card blue h-100"><div class="stat-icon"><i class="bi bi-check-circle"></i></div><div class="stat-value">{{ number_format($stats['count_paid']) }}</div><div class="stat-label">Transaksi Lunas</div></div></div>
    <div class="col-6 col-md-4"><div class="stat-card orange h-100"><div class="stat-icon"><i class="bi bi-hourglass-split"></i></div><div class="stat-value">{{ number_format($stats['count_pending']) }}</div><div class="stat-label">Transaksi Pending</div></div></div>
</div>

<div class="card table-card">
    <div class="table-toolbar flex-wrap gap-2 p-3 border-bottom">
        <form method="GET" class="row g-2 align-items-center w-100 m-0">
            <div class="col-12 col-sm-6 col-md-2">
                <select name="router_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua Router</option>
                    @foreach ($routers as $rt)<option value="{{ $rt->id }}" @selected((int) request('router_id') === $rt->id)>{{ $rt->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-sm-3 col-md-2">
                <select name="month" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="0">Semua Bulan</option>
                    @foreach ($months as $k => $m)<option value="{{ $k }}" @selected($selMonth === $k)>{{ $m }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-sm-3 col-md-2">
                <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                    @for ($y = now()->year + 1; $y >= 2024; $y--)<option value="{{ $y }}" @selected($selYear === $y)>{{ $y }}</option>@endfor
                </select>
            </div>
            <div class="col-6 col-sm-3 col-md-2">
                <select name="method" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Metode</option>
                    @foreach (['cash' => '💵 Tunai / Cash', 'midtrans' => '💳 Midtrans Snap', 'tripay' => '⚡ Tripay Gateway', 'transfer' => '🏦 Transfer Bank', 'qris' => '📱 QRIS'] as $v => $l)<option value="{{ $v }}" @selected(request('method') === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-6 col-sm-3 col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    @foreach (['paid' => 'Lunas', 'pending' => 'Pending', 'cancel' => 'Batal'] as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-12 col-md-2">
                <div class="input-group input-group-sm">
                    <input type="text" name="q" class="form-control" placeholder="Cari..." value="{{ request('q') }}">
                    <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                </div>
            </div>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Waktu Pembayaran</th><th>Pelanggan</th><th>Router</th><th>Periode Tagihan</th><th>Nominal</th><th>Metode Bayar</th><th>No. Order / Ref</th><th>Status</th><th class="text-center" style="width: 100px;">Aksi</th></tr></thead>
            <tbody>
            @forelse ($payments as $p)
                @php $at = $p->paid_at ?? $p->created_at; @endphp
                <tr>
                    <td><div class="fw-bold">{{ $at?->format('d/m/Y') }}</div><small class="text-muted">{{ $at?->format('H:i:s') }} WIB</small></td>
                    <td><div class="fw-bold text-dark">{{ $p->customer?->full_name }}</div><div class="font-mono small text-primary">{{ $p->customer?->pppoe_username }}</div></td>
                    <td><span class="badge bg-light text-secondary border">{{ $p->customer?->router?->name ?? '-' }}</span></td>
                    <td><span class="fw-semibold">{{ $months[$p->period_month] ?? $p->period_month }} {{ $p->period_year }}</span></td>
                    <td><strong class="text-success fs-6">{{ $rp($p->amount) }}</strong></td>
                    <td>{!! $methodBadges[$p->payment_method] ?? '<span class="badge bg-light text-dark border">'.e(ucfirst($p->payment_method)).'</span>' !!}</td>
                    <td><span class="font-mono small text-muted">{{ $p->midtrans_order_id ?: '-' }}</span>@if ($p->notes)<div class="small text-muted fst-italic" style="font-size: .75rem;">{{ $p->notes }}</div>@endif</td>
                    <td>
                        @if ($p->midtrans_status === 'paid')<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Lunas</span>
                        @elseif ($p->midtrans_status === 'pending')<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>Pending</span>
                        @else<span class="badge bg-danger">{{ ucfirst((string) $p->midtrans_status) }}</span>@endif
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            @if ($p->midtrans_status === 'paid')
                                <a href="{{ route('receipt.show', ['payment' => $p, 'signature' => \App\Services\Pppoe\BillingService::receiptSignature($p)]) }}" target="_blank" class="btn btn-sm btn-outline-primary btn-icon" title="Cetak Kwitansi / Struk"><i class="bi bi-printer"></i></a>
                                @if ($p->customer?->phone)
                                    <form method="POST" action="{{ route('payments.send-wa', $p) }}" class="d-inline" onsubmit="return confirm('Kirim kwitansi pembayaran ini ke nomor WhatsApp {{ addslashes($p->customer->full_name) }} ({{ $p->customer->phone }})?')">
                                        @csrf<button type="submit" class="btn btn-sm btn-outline-success btn-icon" title="Kirim Kwitansi via WhatsApp"><i class="bi bi-whatsapp"></i></button>
                                    </form>
                                @endif
                            @endif
                            @can('manage-settings')
                                <form method="POST" action="{{ route('payments.destroy', $p) }}" class="d-inline" onsubmit="return confirm('Hapus catatan pembayaran ini? (Tindakan ini tidak dapat dibatalkan)')">
                                    @csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="Hapus Transaksi"><i class="bi bi-trash"></i></button>
                                </form>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-5"><i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>Belum ada riwayat pembayaran yang cocok dengan filter yang dipilih.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($payments->hasPages())
        <div class="card-footer d-flex justify-content-between align-items-center py-3">
            <span class="text-muted small">Menampilkan {{ $payments->firstItem() }} - {{ $payments->lastItem() }} dari {{ $payments->total() }} transaksi</span>
            {{ $payments->links('pagination::bootstrap-5') }}
        </div>
    @endif
</div>

<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('payments.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Kasir</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Pelanggan <span class="text-danger">*</span></label>
                    <select name="customer_id" class="form-select" required onchange="document.getElementById('modal_pay_amount').value = this.selectedOptions[0]?.dataset.price || ''">
                        <option value="">-- Pilih Pelanggan --</option>
                        @foreach ($customers as $c)<option value="{{ $c->id }}" data-price="{{ (int) $c->monthly_price }}">{{ $c->full_name }} ({{ $c->pppoe_username }}) — {{ $rp($c->monthly_price) }}</option>@endforeach
                    </select>
                </div>
                @include('customers._pay_fields', ['months' => $months, 'collector' => auth()->user()->name, 'amountId' => 'modal_pay_amount', 'suffix' => 'Pay'])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>
@endsection
