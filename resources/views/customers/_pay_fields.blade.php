{{-- Isian modal Catat Pembayaran (S.NET). Butuh: $months, $collector, $amountId, $suffix. --}}
<div class="row g-3">
    <div class="col-6">
        <label class="form-label fw-bold">Periode Bulan <span class="text-danger">*</span></label>
        <select name="period_month" class="form-select" required>
            @foreach ($months as $k => $m)<option value="{{ $k }}" @selected(now()->month === $k)>{{ $m }}</option>@endforeach
        </select>
    </div>
    <div class="col-6">
        <label class="form-label fw-bold">Periode Tahun <span class="text-danger">*</span></label>
        <input type="number" name="period_year" class="form-control" value="{{ now()->year }}" required min="2024" max="2099">
    </div>
    <div class="col-12">
        <label class="form-label fw-bold">Nominal Pembayaran (Rp) <span class="text-danger">*</span></label>
        <input type="number" name="amount" id="{{ $amountId }}" class="form-control fs-5 fw-bold text-success" required min="1000" step="1000">
    </div>
    <div class="col-12">
        <label class="form-label fw-bold">Metode Pembayaran</label>
        <select name="payment_method" class="form-select">
            <option value="cash">💵 Tunai / Cash (Kasir Kantor)</option>
            <option value="transfer">🏦 Transfer Bank</option>
            <option value="qris">📱 QRIS / E-Wallet</option>
            <option value="other">Lainnya</option>
        </select>
    </div>
    <div class="col-12">
        <label class="form-label">Catatan / Keterangan (Opsional)</label>
        <input type="text" name="notes" class="form-control" placeholder="Contoh: Lunas bayar di loket">
    </div>
    <div class="col-12">
        <label class="form-label fw-bold">Petugas / Teknisi Penerima</label>
        <input type="text" name="collector_name" class="form-control" value="{{ $collector }}" placeholder="Nama teknisi / admin penerima uang">
    </div>
    <div class="col-12">
        <div class="form-check form-switch mt-1">
            <input class="form-check-input" type="checkbox" name="send_wa" value="1" id="checkSendWa{{ $suffix }}" checked>
            <label class="form-check-label fw-bold text-success" for="checkSendWa{{ $suffix }}"><i class="bi bi-whatsapp me-1"></i> Kirim WhatsApp Bukti Pembayaran Lunas ke Pelanggan</label>
        </div>
    </div>
    <div class="col-12">
        <div class="form-check form-switch mt-1">
            <input class="form-check-input" type="checkbox" checked disabled id="checkAutoUnisolir{{ $suffix }}">
            <label class="form-check-label fw-bold text-primary" for="checkAutoUnisolir{{ $suffix }}">Otomatis Buka Isolir di MikroTik jika pelanggan terisolir</label>
        </div>
    </div>
</div>
