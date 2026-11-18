{{-- Daftar tagihan + tombol bayar (gaya portal S.NET). Butuh: $bills, $gateways, $tripayChannels, $payUrl, $statusUrl. --}}
@php($rp = fn ($v) => 'Rp '.number_format($v, 0, ',', '.'))
@php($total = array_sum(array_column($bills, 'amount')))
@if ($total > 0)
    <div class="bill-hero unpaid">
        <div class="bill-hero-title">Total Tagihan &amp; Tunggakan</div>
        <div class="bill-hero-amount">{{ $rp($total) }}</div>
        <div class="bill-hero-subtitle">⚠️ Anda memiliki tagihan yang belum diselesaikan. Segera bayar untuk menghindari isolir atau pemutusan layanan.</div>
    </div>
    <div class="card">
        <div class="ch"><div class="ct">🧾 Rincian Tagihan</div></div>
        <div class="cb">
            <table class="bill-table">
                @foreach ($bills as $b)
                    <tr>
                        <td><strong>Periode {{ $b['label'] }}</strong><div style="font-size:.7rem;color:var(--g400)">Jatuh tempo {{ $b['due']->translatedFormat('d M Y') }}</div></td>
                        <td><span class="bdg {{ $b['is_arrear'] ? 'boff' : '' }}">{{ $b['is_arrear'] ? 'Lewat Jatuh Tempo' : 'Tagihan Berjalan' }}</span></td>
                        <td style="text-align:right;font-weight:800">{{ $rp($b['amount']) }}</td>
                    </tr>
                    @if ($gateways)
                        <tr><td colspan="3" style="padding-top:0">
                            <div style="display:flex;flex-wrap:wrap;gap:6px">
                                @if (in_array('midtrans', $gateways, true))<button type="button" class="btn btn-p btn-sm" data-pay="{{ $b['year'] }}-{{ sprintf('%02d', $b['month']) }}" data-gateway="midtrans">💳 Bayar Online</button>@endif
                                @if (in_array('tripay', $gateways, true))
                                    <select class="fc" data-channel style="width:auto;padding:5px 8px;font-size:.78rem">@foreach ($tripayChannels as $ch)<option value="{{ $ch['code'] }}">{{ $ch['name'] }}</option>@endforeach</select>
                                    <button type="button" class="btn btn-p btn-sm" data-pay="{{ $b['year'] }}-{{ sprintf('%02d', $b['month']) }}" data-gateway="tripay">📱 Bayar QRIS / VA</button>
                                @endif
                            </div>
                        </td></tr>
                    @endif
                @endforeach
            </table>
            @unless ($gateways)<div class="alert ainf" style="margin-top:10px">ℹ️ Pembayaran online belum aktif. Silakan bayar ke kasir / admin S.NET.</div>@endunless
            <div id="payResult" style="margin-top:10px"></div>
        </div>
    </div>
@else
    <div class="bill-hero">
        <div class="bill-hero-title">Status Tagihan Bulan Ini</div>
        <div class="bill-hero-amount" style="font-size:1.45rem">✅ Lunas / Tidak Ada Tagihan</div>
        <div class="bill-hero-subtitle">Terima kasih, pembayaran Anda sudah tertib dan layanan internet dalam kondisi aktif normal.</div>
    </div>
@endif

@push('scripts')
<script>
(function () {
    const csrf = document.querySelector('meta[name=csrf-token]').content;
    const out = document.getElementById('payResult');
    if (!out) return;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const show = (html, cls = 'ainf') => { out.innerHTML = `<div class="alert ${cls}">${html}</div>`; };
    const poll = (order) => {
        const url = @json($statusUrl);
        if (!url) return;
        const t = setInterval(async () => {
            const r = await fetch(url + '?order_id=' + encodeURIComponent(order)).then(r => r.json()).catch(() => ({}));
            if (r.status === 'paid') { clearInterval(t); show('✅ Pembayaran diterima. Memuat ulang…', 'aok'); setTimeout(() => location.reload(), 1500); }
        }, 5000);
    };
    document.querySelectorAll('[data-pay]').forEach(btn => btn.addEventListener('click', async () => {
        btn.disabled = true;
        const channel = btn.parentElement.querySelector('[data-channel]')?.value;
        const res = await fetch(@json($payUrl), { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ period: btn.dataset.pay, gateway: btn.dataset.gateway, channel }) });
        const d = await res.json().catch(() => ({}));
        btn.disabled = false;
        if (!res.ok) return show('⚠️ ' + esc(d.message || 'Gagal memulai pembayaran.'), 'aerr');
        if (d.gateway === 'midtrans') {
            const s = document.createElement('script');
            s.src = d.snap_js; s.dataset.clientKey = d.client_key;
            s.onload = () => window.snap.pay(d.token, { onSuccess: () => poll(d.order_id), onPending: () => poll(d.order_id) });
            document.body.append(s);
        } else {
            show(`<strong>${esc(d.payment_name)} — Rp ${Number(d.amount).toLocaleString('id-ID')}</strong>`
                + (d.pay_code ? `<div>Kode bayar: <span class="code">${esc(d.pay_code)}</span></div>` : '')
                + (d.qr_url ? `<div><img src="${esc(d.qr_url)}" style="max-width:220px;margin-top:8px" alt="QRIS"></div>` : '')
                + (d.checkout_url ? `<div><a href="${esc(d.checkout_url)}" target="_blank" rel="noopener">Buka halaman pembayaran ›</a></div>` : ''));
            poll(d.order_id);
        }
    }));
})();
</script>
@endpush
