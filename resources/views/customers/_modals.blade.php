{{-- Modal aksi cepat Pelanggan Rumahan (sama dengan S.NET lama). Butuh: $customerOptions, $waTemplates, $settings. --}}
@php
    $months = \App\Services\Pppoe\BillingService::MONTHS;
    $collector = auth()->user()->name;
@endphp

{{-- Modal Catat Pembayaran Kasir (per pelanggan) --}}
<div class="modal fade" id="modalPayCustomer" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('payments.store') }}" class="modal-content">
            @csrf <input type="hidden" name="customer_id" id="pay_customer_id" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Pembayaran Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="pay_customer_name">-</div>
                    <div class="font-mono text-muted small" id="pay_customer_username">-</div>
                </div>
                @include('customers._pay_fields', ['amountId' => 'pay_amount', 'suffix' => 'Customer'])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Catat Pembayaran Global (pilih pelanggan) --}}
<div class="modal fade" id="modalPayGlobal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('payments.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cash-stack text-success me-2"></i>Catat Bayar Pelanggan Rumahan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Pelanggan Rumahan <span class="text-danger">*</span></label>
                    <select name="customer_id" class="form-select" required onchange="document.getElementById('global_pay_amount').value = this.selectedOptions[0]?.dataset.price || ''">
                        <option value="">-- Cari Nama / Username PPPoE --</option>
                        @foreach ($customerOptions as $c)
                            <option value="{{ $c->id }}" data-price="{{ (int) $c->monthly_price }}">{{ $c->full_name }} ({{ $c->pppoe_username }}) — Rp {{ number_format($c->monthly_price, 0, ',', '.') }} [Cabang {{ $c->router?->name }}]</option>
                        @endforeach
                    </select>
                </div>
                @include('customers._pay_fields', ['amountId' => 'global_pay_amount', 'suffix' => 'Global'])
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-check-lg me-1"></i> Simpan Pembayaran</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Kirim WhatsApp Cepat --}}
<div class="modal fade" id="modalQuickWa" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('whatsapp.send') }}" class="modal-content">
            @csrf <input type="hidden" name="customer_id" id="wa_customer_id" value="">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-whatsapp text-success me-2"></i>Kirim Notifikasi WhatsApp</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold fs-6" id="wa_customer_name">-</div>
                    <div class="small font-mono text-muted" id="wa_customer_info">-</div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nomor WhatsApp Tujuan <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="wa_inp_phone" class="form-control font-mono" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Pilih Template Pesan</label>
                    <select id="wa_sel_template" class="form-select" onchange="applyQuickTemplate(this.value)">
                        <option value="">-- Pilih Template Pesan --</option>
                        @foreach ($waTemplates as $wt)<option value="{{ $wt->code }}">{{ $wt->name }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Isi Pesan WhatsApp <span class="text-danger">*</span></label>
                    <textarea name="message" id="wa_inp_message" class="form-control font-mono small" rows="7" required placeholder="Tulis isi pesan..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success px-4"><i class="bi bi-send-fill me-1"></i> Kirim WhatsApp</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Ganti Nama & Password Wi-Fi (Push ONT) --}}
<div class="modal fade" id="modalQuickWifi" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" id="formQuickWifi" class="modal-content shadow border-0" onsubmit="document.getElementById('btnSubmitWifi').innerHTML = '<span class=\'spinner-border spinner-border-sm me-1\'></span> Mengirim ke ONT...';">
            @csrf
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-wifi me-2"></i>Ganti Nama &amp; Password Wi-Fi (Push ONT)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border d-flex align-items-center justify-content-between mb-3 py-2">
                    <div>
                        <div class="fw-bold text-dark fs-6" id="wifi_customer_name">-</div>
                        <div class="text-muted small">SN ONT: <strong class="font-mono text-primary" id="wifi_customer_sn">-</strong></div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1"><i class="bi bi-router me-1"></i>TR-069</span>
                </div>
                <div class="card border-primary-subtle bg-light shadow-sm mb-3">
                    <div class="card-body p-3">
                        <div class="text-uppercase fw-bold text-muted mb-2 pb-1 border-bottom border-light-subtle" style="font-size:11px;"><i class="bi bi-info-circle-fill text-primary me-1"></i>Data Wi-Fi Saat Ini</div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-muted small text-uppercase" style="font-size:10px; font-weight:600;">SSID Saat Ini:</div>
                                <div class="fw-bold text-dark fs-6 text-truncate" id="display_current_ssid">-</div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted small text-uppercase" style="font-size:10px; font-weight:600;">Password Saat Ini:</div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-bold font-mono text-primary fs-6" id="display_current_pass">-</span>
                                    <button type="button" class="btn btn-sm btn-link text-primary p-0 ms-1" onclick="copyText(document.getElementById('display_current_pass').dataset.realPass)" title="Salin Sandi"><i class="bi bi-clipboard"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">Nama Wi-Fi / SSID Baru (2.4 GHz &amp; 5 GHz) <small class="text-muted fw-normal">(Kosongkan jika tetap nama lama)</small></label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-broadcast"></i></span>
                            <input type="text" name="wifi_ssid" id="modal_wifi_ssid" class="form-control form-control-lg fw-bold" maxlength="32" placeholder="Biarkan kosong jika tidak diubah (tetap nama lama)"></div>
                        <div class="form-text">Biarkan kosong jika hanya ingin mengganti password. Jika diisi, nama Wi-Fi akan diperbarui untuk sinyal 2.4 GHz &amp; 5 GHz.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Password / Kode Wi-Fi Baru (WPA2) <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="text" name="wifi_pass" id="modal_wifi_pass" class="form-control form-control-lg font-mono fw-bold text-primary" minlength="8" maxlength="63" required placeholder="Minimal 8 karakter">
                            <button type="button" class="btn btn-outline-secondary" onclick="generateRandomWifiPass()" title="Buat Sandi Acak"><i class="bi bi-shuffle me-1"></i>Acak</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="copyText(document.getElementById('modal_wifi_pass').value)" title="Salin Sandi"><i class="bi bi-clipboard"></i></button>
                        </div>
                        <div class="form-text">Minimal 8 karakter. Berlaku sama untuk sinyal Wi-Fi 2.4 GHz &amp; 5 GHz.</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="push_ont" value="1" id="checkPushWifiOnt" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkPushWifiOnt"><i class="bi bi-lightning-charge-fill me-1"></i> Push langsung ke Modem ONT via GenieACS (TR-069)</label>
                            <div class="text-muted small mt-1 ms-4">Konfigurasi baru dikirim ke modem pelanggan memakai driver ONT (path parameter per merek/model).</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSubmitWifi"><i class="bi bi-check2-circle me-1"></i> Simpan &amp; Terapkan Wi-Fi</button>
            </div>
        </form>
    </div>
</div>

{{-- Modal Atur ID & Password Portal Pelanggan --}}
<div class="modal fade" id="modalQuickPortal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="#" id="formQuickPortal" class="modal-content shadow border-0">
            @csrf
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fs-6"><i class="bi bi-person-lock me-2"></i>Atur Akun Portal Pelanggan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-light border mb-3 py-2">
                    <div class="fw-bold text-dark fs-6" id="portal_customer_name">-</div>
                    <div class="text-muted small">Username PPPoE: <strong class="font-mono text-primary" id="portal_customer_pppoe">-</strong></div>
                </div>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-bold">ID / Username Login Portal <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" name="portal_username" id="modal_portal_user" class="form-control form-control-lg font-mono fw-bold" required placeholder="Username portal"></div>
                        <div class="form-text">Digunakan oleh pelanggan saat login ke halaman web portal mandiri.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Ganti Password Baru Portal (Opsional)</label>
                        <div class="input-group"><span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                            <input type="text" name="portal_password" id="modal_portal_pass" class="form-control form-control-lg font-mono fw-bold text-dark" placeholder="Kosongkan jika tidak ingin ubah password">
                            <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('modal_portal_pass').value = String(Math.floor(100000 + Math.random() * 900000))" title="Buat Sandi Acak"><i class="bi bi-shuffle me-1"></i>Acak</button>
                        </div>
                        <div class="form-text">Password portal disimpan terenkripsi sehingga tidak bisa ditampilkan. Isi kolom ini untuk mereset bila pelanggan lupa (minimal 4 karakter).</div>
                    </div>
                    <div class="col-12">
                        <div class="form-check form-switch p-3 bg-light rounded-3 border">
                            <input class="form-check-input ms-0 me-2" type="checkbox" name="send_wa" value="1" id="checkSendWaPortal" checked style="float:none;">
                            <label class="form-check-label fw-bold text-success" for="checkSendWaPortal"><i class="bi bi-whatsapp me-1"></i> Kirim info login &amp; link portal via WhatsApp ke pelanggan</label>
                            <div class="text-muted small mt-1 ms-4" id="portal_wa_phone_info">No WhatsApp: -</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-dark fw-bold px-4"><i class="bi bi-check2-circle me-1"></i> Simpan Akun Portal</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
const waTemplatesList = @json($waTemplates);
const companyName = @json($settings['company_name'] ?? config('snet.name'));
const companyPhone = @json($settings['company_phone'] ?? '');
const portalUrl = @json(route('portal.login'));
const wifiUrl = @json(route('customers.wifi', ['customer' => '__ID__']));
const portalActionUrl = @json(route('customers.portal', ['customer' => '__ID__']));
let activeWaCustomer = null;

function copyText(t) { if (t) navigator.clipboard.writeText(t).then(() => alert('Disalin: ' + t)); }
function generateRandomWifiPass() {
    const chars = 'abcdefghjkmnpqrstuvwxyz23456789';
    document.getElementById('modal_wifi_pass').value = Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}
function applyQuickTemplate(code) {
    if (!code || !activeWaCustomer) return;
    const t = waTemplatesList.find(item => item.code === code);
    if (!t) return;
    const c = activeWaCustomer;
    document.getElementById('wa_inp_message').value = t.message
        .replace(/{nama}/g, c.name).replace(/{username}/g, c.username)
        .replace(/{nama_layanan}/g, companyName).replace(/{company_name}/g, companyName)
        .replace(/{tagihan}/g, 'Rp ' + Number(c.price).toLocaleString('id-ID'))
        .replace(/{jatuh_tempo}/g, 'Tanggal ' + c.due)
        .replace(/{bulan}/g, new Date().toLocaleDateString('id-ID', { month: 'long', year: 'numeric' }))
        .replace(/{cs_phone}/g, companyPhone).replace(/{link_portal}/g, portalUrl);
}

document.addEventListener('DOMContentLoaded', function () {
    const modal = (id) => bootstrap.Modal.getOrCreateInstance(document.getElementById(id));
    document.querySelectorAll('.btn-quick-pay').forEach(btn => btn.addEventListener('click', function () {
        document.getElementById('pay_customer_id').value = this.dataset.id;
        document.getElementById('pay_customer_name').textContent = this.dataset.name;
        document.getElementById('pay_customer_username').textContent = 'Username: ' + this.dataset.username;
        document.getElementById('pay_amount').value = this.dataset.price;
        modal('modalPayCustomer').show();
    }));
    document.querySelectorAll('.btn-quick-wa').forEach(btn => btn.addEventListener('click', function () {
        activeWaCustomer = { ...this.dataset };
        document.getElementById('wa_customer_id').value = this.dataset.id;
        document.getElementById('wa_customer_name').textContent = this.dataset.name;
        document.getElementById('wa_customer_info').textContent = 'Username: ' + this.dataset.username + ' | Tagihan: Rp ' + Number(this.dataset.price).toLocaleString('id-ID');
        document.getElementById('wa_inp_phone').value = this.dataset.phone;
        const code = this.dataset.status === 'isolated' ? 'isolir' : 'reminder_h3';
        document.getElementById('wa_sel_template').value = code;
        applyQuickTemplate(code);
        modal('modalQuickWa').show();
    }));
    document.querySelectorAll('.btn-quick-wifi').forEach(btn => btn.addEventListener('click', function () {
        document.getElementById('formQuickWifi').action = wifiUrl.replace('__ID__', this.dataset.id);
        document.getElementById('wifi_customer_name').textContent = this.dataset.name;
        document.getElementById('wifi_customer_sn').textContent = this.dataset.sn || '-';
        document.getElementById('display_current_ssid').textContent = this.dataset.realSsid || '(Belum diatur)';
        const cur = document.getElementById('display_current_pass');
        cur.dataset.realPass = this.dataset.pass || '';
        cur.textContent = this.dataset.pass || '(Belum tersimpan)';
        document.getElementById('modal_wifi_ssid').value = '';
        document.getElementById('modal_wifi_pass').value = this.dataset.pass || '';
        document.getElementById('checkPushWifiOnt').disabled = !this.dataset.sn;
        modal('modalQuickWifi').show();
    }));
    document.querySelectorAll('.btn-quick-portal').forEach(btn => btn.addEventListener('click', function () {
        document.getElementById('formQuickPortal').action = portalActionUrl.replace('__ID__', this.dataset.id);
        document.getElementById('portal_customer_name').textContent = this.dataset.name;
        document.getElementById('portal_customer_pppoe').textContent = this.dataset.username || '-';
        document.getElementById('modal_portal_user').value = this.dataset.portalUser || this.dataset.username || '';
        document.getElementById('modal_portal_pass').value = '';
        const chk = document.getElementById('checkSendWaPortal');
        document.getElementById('portal_wa_phone_info').textContent = this.dataset.phone ? 'Nomor WhatsApp: ' + this.dataset.phone : 'Pelanggan tidak memiliki nomor WhatsApp tersimpan.';
        chk.disabled = !this.dataset.phone; chk.checked = !!this.dataset.phone;
        modal('modalQuickPortal').show();
    }));
});
</script>
@endpush
