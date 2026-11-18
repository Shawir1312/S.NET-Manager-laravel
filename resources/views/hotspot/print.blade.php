@php
    $first = $vouchers->first();
    $title = $batch ? 'Voucher '.($first->profile?->name ?? 'Custom').' - '.$vouchers->count().'pcs - Batch '.$batch : 'Voucher '.$first->username;
    $unit = fn ($u) => ['minutes' => 'Mnt', 'hours' => 'Jam', 'days' => 'Hari'][$u] ?? $u;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('snet/app.css') }}">
    <link rel="stylesheet" href="{{ asset('snet/print.css') }}" media="print">
    <style>
        body { background: #f5f5f5; padding: 20px; font-family: Arial, sans-serif; }
        .voucher-grid { display: grid; grid-template-columns: repeat(auto-fill, 27mm); gap: 1mm; justify-content: center; background: white; padding: 5mm; max-width: 210mm; margin: 0 auto; }
        .voucher-card { border: 1px dashed #000; padding: 2px; width: 28mm; box-sizing: border-box; display: flex; flex-direction: column; background: #fff; overflow: hidden; position: relative; page-break-inside: avoid; }
        @media print {
            @page { margin: 5mm; size: A4 portrait; }
            body { background: white; padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .voucher-grid { padding: 0; margin: 0; max-width: none; display: grid !important; grid-template-columns: repeat(7, 28mm) !important; gap: 1mm !important; justify-content: start !important; }
            .voucher-card { width: 100% !important; margin: 0 !important; }
        }
    </style>
</head>
<body>
<div class="no-print mb-3 d-flex gap-2 align-items-center flex-wrap p-3 bg-white rounded shadow-sm">
    <img src="{{ asset('snet/logo.png') }}" height="36" alt="Logo">
    <div class="flex-fill">
        <div class="fw-700">{{ $vouchers->count() }} Voucher @if ($batch)— Batch: <span class="font-mono">{{ $batch }}</span>@endif</div>
        <div class="text-muted" style="font-size:.75rem;">Profil: {{ $first->profile?->name ?? '-' }}</div>
    </div>
    <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Cetak</button>
    <a href="{{ route('vouchers.index', $batch ? ['batch_id' => $batch] : []) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Kembali</a>
    <a href="{{ route('vouchers.create') }}" class="btn btn-outline-primary"><i class="bi bi-plus me-1"></i>Generate Lagi</a>
</div>

<div class="voucher-grid">
@foreach ($vouchers as $index => $v)
    @php
        $p = $v->profile;
        $duration = ! $p || (int) $p->duration_value === 0 ? 'Unlimited' : $p->duration_value.' '.$unit($p->duration_unit);
        $validity = ! $p || (int) $p->validity_value === 0 ? 'Unlimited' : $p->validity_value.' '.$unit($p->validity_unit);
        $price = $p && $p->price > 0 ? format_price($p->price) : 'Gratis';
    @endphp
<div class="voucher-card">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #000; padding-bottom: 1px; margin-bottom: 1px; height: 12px;">
        <div style="font-size: 3.5pt; font-weight: 800; width: 35%; line-height: 1; word-wrap: break-word; overflow: hidden; max-height: 12px;">{{ $p?->display_name ?: $p?->name }}</div>
        <div style="width: 30%; text-align: center;"><img src="{{ asset('snet/logo.png') }}" style="max-height: 11px; max-width: 100%; display: inline-block;" alt="Logo"></div>
        <div style="font-size: 4pt; font-family: monospace; font-weight: bold; line-height: 1; width: 35%; text-align: right;">No: {{ $index + 1 }}</div>
    </div>
    <div style="flex-grow: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; padding: 1px 0;">
        @if ($v->username === $v->password)
            <div style="font-size: 4pt; color: #333; margin-bottom: 1px;">KODE VOUCHER</div>
            <div style="font-weight: 900; font-size: 9.5pt; letter-spacing: 0.5px;">{{ $v->username }}</div>
        @else
            <div style="font-size: 4pt; color: #333;">USER</div>
            <div style="font-weight: 900; font-size: 8pt; letter-spacing: 0.5px;">{{ $v->username }}</div>
            <div style="font-size: 4pt; color: #333; margin-top: 1px;">PASS</div>
            <div style="font-weight: 900; font-size: 8pt; letter-spacing: 0.5px;">{{ $v->password }}</div>
        @endif
    </div>
    <div style="font-size: 4.5pt; border-top: 1px dashed #000; padding-top: 1px; line-height: 1.1;">
        <div style="display: flex; justify-content: space-between;"><span>Masa Aktif:</span><span style="font-weight: bold;">{{ $validity }}</span></div>
        <div style="display: flex; justify-content: space-between;"><span>Durasi:</span><span style="font-weight: bold;">{{ $duration }}</span></div>
        <div style="display: flex; justify-content: space-between; margin-top: 1px;"><span style="font-weight: bold; font-size: 5.5pt;">Harga:</span><span style="font-weight: 900; font-size: 6pt; color: #000;">{{ $price }}</span></div>
    </div>
</div>
@endforeach
</div>
<script>
if (new URLSearchParams(location.search).get('autoprint') === '1') { setTimeout(() => window.print(), 500); }
</script>
</body>
</html>
