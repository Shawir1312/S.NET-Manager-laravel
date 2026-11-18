<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kwitansi {{ $payment->midtrans_order_id }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <style>body{background:#f3f5f9} .receipt{max-width:420px} @media print{.no-print{display:none}body{background:#fff}}</style>
</head>
<body>
<div class="receipt mx-auto my-4 px-3">
    <div class="card shadow-sm"><div class="card-body">
        <div class="text-center mb-3">
            <div class="fw-bold fs-5">{{ $company['company_name'] ?? config('app.name') }}</div>
            <div class="small text-body-secondary">{{ $company['company_address'] ?? '' }}</div>
        </div>
        <div class="text-center mb-3"><span class="badge text-bg-success fs-6">LUNAS</span></div>
        <dl class="row small mb-0">
            <dt class="col-5 fw-normal text-body-secondary">No. Kwitansi</dt><dd class="col-7 font-monospace">{{ $payment->midtrans_order_id }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">Pelanggan</dt><dd class="col-7">{{ $payment->customer->full_name }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">Username</dt><dd class="col-7 font-monospace">{{ $payment->customer->pppoe_username }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">Periode</dt><dd class="col-7">{{ \App\Services\Pppoe\BillingService::MONTHS[$payment->period_month] ?? $payment->period_month }} {{ $payment->period_year }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">Metode</dt><dd class="col-7">{{ strtoupper($payment->payment_method) }}</dd>
            <dt class="col-5 fw-normal text-body-secondary">Dibayar</dt><dd class="col-7">{{ $payment->paid_at?->format('d M Y H:i') }}</dd>
        </dl>
        <hr>
        <div class="d-flex justify-content-between fs-5 fw-bold"><span>Total</span><span>Rp {{ number_format($payment->amount, 0, ',', '.') }}</span></div>
    </div></div>
    <div class="text-center mt-3 no-print"><button class="btn btn-outline-secondary btn-sm" onclick="print()">Cetak</button></div>
</div>
</body>
</html>
