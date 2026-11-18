/* ONT AUTO CONFIGURATION — Wizard Step 2-9 (layanan, WAN/VLAN, SSID, binding, preview, apply, monitor, verifikasi). */
(function () {
    'use strict';
    const { h, badge, icon, fmtDate } = window.OntDevice;
    const cfg = window.WIZARD_CFG;
    const root = document.getElementById('wizard');
    const stepsEl = document.querySelectorAll('.wizard-step');
    let S = null; // state wizard

    const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    }));

    function setStep(n) {
        S.step = n;
        stepsEl.forEach(li => {
            const no = Number(li.dataset.step);
            li.classList.toggle('active', no === n);
            li.classList.toggle('done', no < n);
            li.classList.remove('locked');
        });
        render();
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // ---------- form helpers ----------
    let fid = 0;
    const field = (label, control, help) => {
        if (!control.id) control.id = 'wf' + (++fid);
        return h('div', { class: 'mb-3' }, h('label', { class: 'form-label', for: control.id }, label), control, help ? h('div', { class: 'form-text' }, help) : null);
    };
    function input(attrs, get, set) {
        const el = h('input', { class: 'form-control', ...attrs });
        el.value = get() ?? '';
        el.addEventListener('input', () => set(attrs.type === 'number' ? (el.value === '' ? null : Number(el.value)) : el.value));
        return el;
    }
    function select(options, get, set, attrs = {}) {
        const el = h('select', { class: 'form-select', ...attrs }, ...options.map(([v, t]) => h('option', { value: v }, t)));
        el.value = get() ?? '';
        el.addEventListener('change', () => set(el.value));
        return el;
    }
    function check(label, get, set, id, attrs = {}) {
        const el = h('input', { class: 'form-check-input', type: 'checkbox', id, ...attrs });
        el.checked = !!get();
        el.addEventListener('change', () => set(el.checked));
        return h('div', { class: 'form-check' }, el, h('label', { class: 'form-check-label', for: id }, label));
    }
    const nav = (back, nextLabel, onNext, nextDisabled = false) => h('div', { class: 'd-flex gap-2 justify-content-between mt-3' },
        back ? h('button', { class: 'btn btn-outline-secondary', type: 'button', onclick: back }, icon('bi-arrow-left'), ' BACK') : h('span'),
        onNext ? h('button', { class: 'btn btn-primary', type: 'button', onclick: onNext, disabled: nextDisabled }, nextLabel, ' ', icon('bi-arrow-right')) : null);
    const card = (title, ...body) => h('div', { class: 'card' }, h('div', { class: 'card-header' }, title), h('div', { class: 'card-body' }, ...body));
    const alertBox = (tone, ...c) => h('div', { class: 'alert alert-' + tone + ' small' }, ...c);
    // replaceChildren() mengubah null menjadi teks "null", jadi buang nilai kosong dulu.
    const fill = (el, ...kids) => el.replaceChildren(...kids.flat().filter(k => k !== null && k !== undefined && k !== false));

    // ---------- payload ----------
    function payload() {
        const pppoe = S.service_type === 'pppoe';
        return {
            device_id: S.ctx.device_id,
            idempotency_key: S.key,
            template_id: S.template_id || null,
            service_type: S.service_type,
            confirm_overwrite: !!S.confirm_overwrite,
            wan: {
                mode: S.wan.mode,
                existing_path: S.wan.mode === 'existing' ? S.wan.existing_path : null,
                slot: S.wan.slot,
                name: S.wan.name || null,
                vlan_id: S.wan.vlan_id,
                vlan_priority: S.wan.vlan_priority,
                service_list: pppoe ? 'INTERNET' : S.wan.service_list,
                nat_enabled: pppoe ? S.wan.nat_enabled : null,
                pppoe_username: pppoe ? S.wan.pppoe_username : null,
                pppoe_password: pppoe ? (S.wan.pppoe_password || null) : null,
            },
            ssids: Object.values(S.ssids).filter(s => s.change).map(s => ({
                index: s.index,
                ssid_name: s.ssid_name || null,
                password: s.security === 'None' ? null : (s.password || null),
                security: s.security || null,
                enabled: s.enabled,
                hidden: s.hidden,
            })),
            binding: { lan: [...S.bind.lan].sort(), wlan: [...S.bind.wlan].sort() },
        };
    }

    function applyTemplate(t) {
        const p = t.payload || {};
        S.template_id = t.id;
        if (p.service_type) S.service_type = p.service_type;
        Object.assign(S.wan, {
            slot: p.wan?.slot ?? S.wan.slot, vlan_id: p.wan?.vlan_id ?? S.wan.vlan_id, vlan_priority: p.wan?.vlan_priority ?? null,
            service_list: p.wan?.service_list ?? S.wan.service_list, nat_enabled: p.wan?.nat_enabled ?? true, name: p.wan?.name ?? '',
        });
        if (S.service_type === 'pppoe') S.wan.slot = S.ctx.capabilities.pppoe_slot;
        S.bind.lan = new Set((p.binding?.lan || []).filter(n => n <= S.ctx.lan_count));
        S.bind.wlan = new Set((p.binding?.wlan || []).filter(n => S.ctx.ssids.some(s => s.index === n)));
        (p.ssids || []).forEach(ts => {
            const s = S.ssids[ts.index];
            if (s) Object.assign(s, { change: true, ssid_name: ts.ssid_name ?? s.ssid_name, security: ts.security ?? s.security, enabled: ts.enabled ?? s.enabled, hidden: ts.hidden ?? s.hidden });
        });
        OntApp.toast('Template "' + t.name + '" diterapkan. Password tetap diisi manual.', 'info');
    }

    // Data pelanggan S.NET: kredensial PPPoE, VLAN, nama/password WiFi; {nama} di template diganti nama depan.
    function applyPrefill(pre, tpl) {
        S.service_type = pre.service_type;
        if (pre.service_type === 'pppoe') {
            S.wan.slot = S.ctx.capabilities.pppoe_slot;
            Object.assign(S.wan, { pppoe_username: pre.pppoe_username || '', pppoe_password: pre.pppoe_password || '' });
            if (pre.vlan_id) S.wan.vlan_id = pre.vlan_id;
        }
        const fixed = new Set((tpl?.payload?.ssids || []).map(t => t.index));
        Object.values(S.ssids).forEach(s => {
            if (s.ssid_name && s.ssid_name.includes('{nama}')) s.ssid_name = s.ssid_name.replaceAll('{nama}', pre.first_name || '');
            if (pre.service_type === 'pppoe' && S.bind.wlan.has(s.index) && !fixed.has(s.index)) {
                Object.assign(s, { change: true, ssid_name: pre.wifi_ssid || s.ssid_name, password: pre.wifi_pass || '' });
            }
        });
    }

    // ---------- render per step ----------
    function render() {
        const fn = { 2: stepService, 3: stepWan, 4: stepSsid, 5: stepBinding, 6: stepPreview, 8: stepMonitor }[S.step];
        root.replaceChildren(fn());
    }

    function stepService() {
        const opt = (value, title, desc, ic) => {
            const id = 'svc_' + value;
            const el = h('input', { class: 'btn-check', type: 'radio', name: 'svc', id, value });
            el.checked = S.service_type === value;
            el.addEventListener('change', () => {
                S.service_type = value;
                if (value === 'pppoe') { S.wan.slot = S.ctx.capabilities.pppoe_slot; S.wan.service_list = 'INTERNET'; }
                render();
            });
            return h('div', { class: 'col-md-6' }, el, h('label', { class: 'btn btn-outline-primary w-100 text-start p-3 svc-option', for: id },
                h('div', { class: 'fw-semibold' }, icon(ic), ' ', title), h('div', { class: 'small' }, desc)));
        };
        const templates = S.templates.filter(t => !S.service_type || t.service_type === S.service_type);
        return card('Step 2 · Pilih Jenis Layanan',
            S.ctx.mode === 'simulation' ? alertBox('warning', icon('bi-cone-striped'), ' Mode SIMULASI: konfigurasi tidak dikirim ke ONT.') : null,
            S.ctx.detection.simulation_only && S.ctx.mode === 'production' ? alertBox('danger', 'Model ini belum diverifikasi: mode produksi akan menolak konfigurasi.') : null,
            h('div', { class: 'row g-3 mb-3' },
                opt('bridge', 'HOTSPOT / BRIDGE', 'WAN bridge (IP_Bridged / PPPoE_Bridged), VLAN manual.', 'bi-broadcast-pin'),
                opt('pppoe', 'PPPoE', 'WAN routed (IP_Routed) dengan username & password PPPoE.', 'bi-person-badge')),
            templates.length ? field('Template (opsional)', select([['', '— tanpa template —'], ...templates.map(t => [String(t.id), t.name + (t.is_default ? ' (default)' : '')])],
                () => S.template_id ? String(S.template_id) : '', v => { const t = S.templates.find(x => String(x.id) === v); if (t) { applyTemplate(t); render(); } else S.template_id = null; })) : null,
            nav(null, 'LANJUT', () => setStep(3), !S.service_type));
    }

    function stepWan() {
        const pppoe = S.service_type === 'pppoe';
        const wans = S.ctx.wans;
        const modeSel = select([['new', 'Buat WAN baru (AddObject)'], ...(wans.length ? [['existing', 'Gunakan WAN yang sudah ada']] : [])],
            () => S.wan.mode, v => { S.wan.mode = v; render(); });
        const wanTable = h('div', { class: 'table-responsive mb-3' }, h('table', { class: 'table table-sm small mb-0' },
            h('thead', {}, h('tr', {}, ...['WAN', 'Slot', 'Nama', 'Mode', 'VLAN', 'Service'].map(t => h('th', {}, t)))),
            h('tbody', {}, ...(wans.length ? wans.map(w => h('tr', {}, h('td', { class: 'font-monospace' }, w.short_path), h('td', {}, w.slot.toUpperCase()),
                h('td', {}, w.name || '-'), h('td', {}, w.connection_type || '-'), h('td', {}, w.vlan_id ?? '-'), h('td', {}, w.service_list || '-')))
                : [h('tr', {}, h('td', { colspan: 6, class: 'text-body-secondary' }, 'Belum ada WAN.'))]))));

        const slotSel = select([['ip', 'WAN IP (WANIPConnection)'], ['ppp', 'WAN PPP (WANPPPConnection)']], () => S.wan.slot, v => { S.wan.slot = v; render(); },
            { disabled: pppoe || S.wan.mode === 'existing' });
        const ctype = pppoe ? 'IP_Routed' : (S.wan.slot === 'ppp' ? 'PPPoE_Bridged' : 'IP_Bridged');

        const cols = [
            h('div', { class: 'col-md-6' }, field('Mode WAN', modeSel)),
            S.wan.mode === 'existing' ? h('div', { class: 'col-md-6' }, field('WAN yang akan diubah', select([['', '— pilih —'],
                ...wans.map(w => [w.path, `${w.short_path} · ${w.name || ''}`])], () => S.wan.existing_path, v => {
                S.wan.existing_path = v; const w = wans.find(x => x.path === v); if (w) S.wan.slot = w.slot; render();
            }), 'Perubahan WAN yang sudah ada memerlukan konfirmasi di Preview.')) : null,
            h('div', { class: 'col-md-6' }, field('WAN Slot', slotSel, pppoe ? 'PPPoE memakai slot ' + S.ctx.capabilities.pppoe_slot.toUpperCase() + ' (username/password PPPoE hanya ada di slot ini).' : null)),
            h('div', { class: 'col-md-6' }, field('WAN Connection Type', h('input', { class: 'form-control', value: ctype, disabled: true }))),
            h('div', { class: 'col-md-6' }, field('WAN Name', input({ maxlength: 64, placeholder: 'otomatis, mis. INTERNET_B_VID_100' }, () => S.wan.name, v => S.wan.name = v))),
            h('div', { class: 'col-md-3 col-6' }, field('VLAN ID *', input({ type: 'number', min: S.ctx.capabilities.vlan_range?.[0] ?? 1, max: S.ctx.capabilities.vlan_range?.[1] ?? 4094 }, () => S.wan.vlan_id, v => S.wan.vlan_id = v))),
            h('div', { class: 'col-md-3 col-6' }, field('VLAN Priority', input({ type: 'number', min: 0, max: 7, placeholder: '0-7' }, () => S.wan.vlan_priority, v => S.wan.vlan_priority = v))),
            h('div', { class: 'col-md-6' }, field('Service List', select(pppoe ? [['INTERNET', 'INTERNET']] : [['INTERNET', 'INTERNET'], ['Other', 'Other']],
                () => S.wan.service_list, v => S.wan.service_list = v, { disabled: pppoe }))),
        ];
        if (pppoe) {
            cols.push(
                h('div', { class: 'col-md-6' }, field('PPPoE Username *', input({ maxlength: 64, autocomplete: 'off' }, () => S.wan.pppoe_username, v => S.wan.pppoe_username = v))),
                h('div', { class: 'col-md-6' }, field('PPPoE Password' + (S.wan.mode === 'new' ? ' *' : ''), input({ type: 'password', maxlength: 64, autocomplete: 'new-password' }, () => S.wan.pppoe_password, v => S.wan.pppoe_password = v),
                    S.wan.mode === 'existing' ? 'Kosongkan bila tidak diubah.' : null)),
                h('div', { class: 'col-md-6' }, check('NAT Enable', () => S.wan.nat_enabled, v => S.wan.nat_enabled = v, 'natEn')));
        }
        return card('Step 3 · Konfigurasi WAN & VLAN', h('div', { class: 'info-label mb-1' }, 'WAN di ONT saat ini'), wanTable,
            h('div', { class: 'row g-2' }, ...cols), nav(() => setStep(2), 'LANJUT', () => {
                const err = [];
                if (!S.wan.vlan_id) err.push('VLAN ID wajib diisi.');
                if (S.wan.mode === 'existing' && !S.wan.existing_path) err.push('Pilih WAN yang akan diubah.');
                if (pppoe && !S.wan.pppoe_username) err.push('PPPoE Username wajib diisi.');
                if (pppoe && S.wan.mode === 'new' && !S.wan.pppoe_password) err.push('PPPoE Password wajib diisi.');
                if (err.length) return OntApp.toast(err.join(' '), 'danger');
                setStep(4);
            }));
    }

    function stepSsid() {
        const rows = Object.values(S.ssids).map(s => {
            const dis = !s.change;
            const sec = select([['', '— tidak diubah —'], ['11i', 'WPA2 (11i)'], ['WPAand11i', 'WPA/WPA2 (WPAand11i)'], ['None', 'Open System (None)']],
                () => s.security, v => { s.security = v || null; if (v === 'None') s.password = ''; render(); }, { disabled: dis, 'aria-label': `Keamanan SSID ${s.index}` });
            return h('tr', {},
                h('td', {}, check('SSID ' + s.index, () => s.change, v => { s.change = v; render(); }, 'chg' + s.index), h('div', { class: 'small text-body-secondary' }, s.band || '')),
                h('td', {}, input({ maxlength: 32, disabled: dis, 'aria-label': `Nama WiFi SSID ${s.index}` }, () => s.ssid_name, v => s.ssid_name = v)),
                h('td', {}, sec),
                h('td', {}, input({ type: 'password', maxlength: 63, placeholder: s.security === 'None' ? 'tanpa password' : 'kosong = tidak diubah', disabled: dis || s.security === 'None' || !s.security, autocomplete: 'new-password', 'aria-label': `Password SSID ${s.index}` }, () => s.password, v => s.password = v)),
                h('td', {}, check('', () => s.enabled, v => s.enabled = v, 'en' + s.index, { disabled: dis, 'aria-label': `SSID ${s.index} aktif` })),
                h('td', {}, check('', () => s.hidden, v => s.hidden = v, 'hd' + s.index, { disabled: dis, 'aria-label': `SSID ${s.index} hidden` })));
        });
        return card('Step 4 · SSID & WiFi',
            h('p', { class: 'small text-body-secondary' }, `Perangkat ini memiliki ${S.ctx.ssids.length} SSID. Centang SSID yang ingin diubah; yang tidak dicentang tidak disentuh.`),
            h('div', { class: 'table-responsive' }, h('table', { class: 'table table-sm align-middle' },
                h('thead', {}, h('tr', {}, ...['SSID', 'Nama WiFi', 'Keamanan', 'Password (8-63)', 'Aktif', 'Hidden'].map(t => h('th', {}, t)))),
                h('tbody', {}, ...rows))),
            nav(() => setStep(3), 'LANJUT', () => setStep(5)));
    }

    function stepBinding() {
        const box = (type, n, label) => check(label, () => S.bind[type].has(n), v => { v ? S.bind[type].add(n) : S.bind[type].delete(n); }, `b_${type}${n}`);
        const lan = Array.from({ length: S.ctx.lan_count }, (_, i) => box('lan', i + 1, 'LAN ' + (i + 1)));
        const wlan = S.ctx.ssids.map(s => box('wlan', s.index, `SSID ${s.index}${s.ssid ? ' · ' + s.ssid : ''}${s.band ? ' (' + s.band + ')' : ''}`));
        const method = S.ctx.capabilities.binding_method;
        return card('Step 5 · LAN / WLAN Interface Binding',
            method === 'wan_plus_lan' ? alertBox('info', icon('bi-info-circle'), ' ZTE F670L: interface WAN (' + (S.wan.slot === 'ppp' ? 'WANPPPConnection' : 'WANIPConnection') + ') otomatis ikut dikirim bersama LAN/WLAN yang dipilih.') : null,
            method === 'flags' ? alertBox('info', icon('bi-info-circle'), ' Binding model ini berupa flag per port (LAN 1-4, SSID 1-4).') : null,
            h('div', { class: 'row' },
                h('div', { class: 'col-md-4' }, h('div', { class: 'info-label mb-2' }, 'LAN'), ...lan),
                h('div', { class: 'col-md-8' }, h('div', { class: 'info-label mb-2' }, 'WLAN'), h('div', { class: 'row' }, ...wlan.map(w => h('div', { class: 'col-sm-6' }, w))))),
            nav(() => setStep(4), 'PREVIEW', () => setStep(6)));
    }

    function stepPreview() {
        const body = h('div', {}, h('div', { class: 'text-center py-4' }, h('span', { class: 'spinner-border' }), h('div', { class: 'mt-2' }, 'Menyusun rencana konfigurasi dari data ONT terbaru…')));
        const wrap = card('Step 6 · Preview Konfigurasi', body);
        OntApp.api(`${cfg.apiOnt}/${S.ctx.device_id}/preview`, { method: 'POST', body: payload() }).then(p => {
            S.preview = p;
            const sm = p.summary || {};
            const kv = (k, v) => h('tr', {}, h('th', { class: 'w-50 fw-normal text-body-secondary' }, k), h('td', {}, v === null || v === undefined || v === '' ? '-' : String(v)));
            const section = (t, rows) => h('div', { class: 'col-md-6' }, h('div', { class: 'info-label mb-1' }, t), h('table', { class: 'table table-sm small' }, h('tbody', {}, ...rows)));
            const needsConfirm = (p.errors || []).some(e => e.includes('Centang konfirmasi'));

            const steps = h('ol', { class: 'small mb-0' }, ...(p.steps || []).map(st => h('li', {}, st.description,
                st.values ? h('ul', { class: 'font-monospace' }, ...st.values.map(v => h('li', {}, v.path.replace('InternetGatewayDevice.', ''), ' = ', h('strong', {}, String(v.value)), ' ',
                    (p.unverified_keys || []).includes(v.key) ? badge('warning', 'belum terverifikasi') : null))) : h('div', { class: 'font-monospace text-body-secondary' }, st.object))));

            const confirm = h('input', { class: 'form-check-input', type: 'checkbox', id: 'confirmApply' });
            const btnApply = h('button', { class: 'btn btn-success', type: 'button', disabled: true }, icon('bi-send-check'), ' APPLY CONFIGURATION');
            confirm.addEventListener('change', () => btnApply.disabled = !confirm.checked || !p.executable);
            btnApply.addEventListener('click', () => apply(btnApply));

            fill(body, 
                p.mode === 'simulation' ? alertBox('warning', icon('bi-cone-striped'), ' MODE SIMULASI: APPLY hanya mensimulasikan langkah, tidak ada yang dikirim ke ONT.') : alertBox('danger', icon('bi-broadcast'), ' MODE PRODUKSI: konfigurasi akan dikirim ke ONT asli.'),
                ...(p.errors || []).map(e => alertBox('danger', icon('bi-x-octagon'), ' ', e)),
                ...(p.production_blockers || []).map(e => alertBox(p.mode === 'production' ? 'danger' : 'secondary', icon('bi-shield-exclamation'), p.mode === 'production' ? ' ' : ' (Produksi) ', e)),
                discoverBox(p),
                ...(p.warnings || []).map(e => alertBox('warning', icon('bi-exclamation-triangle'), ' ', e)),
                needsConfirm ? check('Saya mengizinkan perubahan WAN/SSID yang sudah ada (konfirmasi eksplisit).', () => S.confirm_overwrite, v => { S.confirm_overwrite = v; render(); }, 'confOverwrite') : null,
                h('div', { class: 'row' },
                    section('ONT Information', [kv('Vendor', sm.ont?.vendor), kv('Model', sm.ont?.model), kv('Serial Number', sm.ont?.serial_number), kv('Firmware', sm.ont?.firmware)]),
                    section('WAN Configuration', [kv('Type', sm.wan?.type), kv('Mode', sm.wan?.mode), kv('Slot', sm.wan?.slot), kv('Target', sm.wan?.target), kv('Nama', sm.wan?.name),
                        kv('VLAN', sm.wan?.vlan), kv('Priority', sm.wan?.vlan_priority), kv('Service List', sm.wan?.service_list), sm.wan?.pppoe_username ? kv('Username', sm.wan.pppoe_username) : null, sm.wan?.nat !== null ? kv('NAT', sm.wan?.nat ? 'Enable' : 'Disable') : null].filter(Boolean)),
                    section('SSID Configuration', (sm.ssids || []).length ? sm.ssids.map(s => kv('SSID ' + s.index, [s.name, s.security, s.password_changed ? 'password diubah' : null, s.enabled === false ? 'nonaktif' : null].filter(Boolean).join(' · ') || 'tidak diubah')) : [kv('SSID', 'tidak diubah')]),
                    section('Binding', [sm.binding?.wan_interface ? kv('WAN Interface', sm.binding.wan_interface) : null,
                        kv('LAN', (sm.binding?.lan || []).map(n => 'LAN ' + n).join(', ') || '-'), kv('WLAN', (sm.binding?.wlan || []).map(n => 'SSID ' + n).join(', ') || '-')].filter(Boolean))),
                h('details', { class: 'mb-3' }, h('summary', {}, 'Langkah teknis TR-069 (', (p.steps || []).length, ' langkah)'), steps),
                h('div', { class: 'form-check mb-2' }, confirm, h('label', { class: 'form-check-label', for: 'confirmApply' }, 'Saya sudah memeriksa konfigurasi di atas dan siap menerapkannya.')),
                !p.executable ? h('div', { class: 'small text-danger mb-2' }, 'Perbaiki error di atas terlebih dahulu (BACK).') : null,
                h('div', { class: 'd-flex gap-2 justify-content-between' },
                    h('button', { class: 'btn btn-outline-secondary', type: 'button', onclick: () => setStep(5) }, icon('bi-arrow-left'), ' BACK'), btnApply));
        }).catch(e => fill(body, alertBox('danger', e.message), nav(() => setStep(5))));
        return wrap;
    }

    // Parameter WAN slot ini belum terverifikasi karena objeknya belum ada di ONT -> tawarkan discovery.
    function discoverBox(p) {
        const slot = S.wan.slot;
        const missing = (p.unverified_keys || []).filter(k => k.startsWith('wan.' + slot + '.'));
        if (p.mode !== 'production' || !missing.length) return null;
        const btn = h('button', { class: 'btn btn-sm btn-warning mt-2', type: 'button' }, icon('bi-search'), ' Temukan parameter WAN ' + slot.toUpperCase() + ' di ONT ini');
        btn.addEventListener('click', async () => {
            if (!btn.dataset.confirm) {
                btn.dataset.confirm = '1';
                btn.replaceChildren(icon('bi-exclamation-triangle'), ' Klik lagi: buat objek ' + (slot === 'ppp' ? 'WANPPPConnection' : 'WANIPConnection') + ' kosong sementara, baca, lalu hapus');
                return;
            }
            btn.disabled = true;
            btn.replaceChildren(h('span', { class: 'spinner-border spinner-border-sm me-1' }), 'Mencari parameter di ONT…');
            try {
                const r = await OntApp.api(`${cfg.apiOnt}/${S.ctx.device_id}/discover`, { method: 'POST', body: { slot } });
                OntApp.toast(r.message, r.deleted ? 'success' : 'warning');
                render(); // susun ulang preview dengan parameter yang baru terverifikasi
            } catch (e) {
                OntApp.toast(e.message, 'danger');
                btn.disabled = false;
                delete btn.dataset.confirm;
                btn.replaceChildren(icon('bi-search'), ' Temukan parameter WAN ' + slot.toUpperCase() + ' di ONT ini');
            }
        });
        return alertBox('warning', icon('bi-lightbulb'), ' ', missing.length + ' parameter WAN ' + slot.toUpperCase()
            + ' belum terverifikasi karena ONT ini belum punya objek WAN ' + slot.toUpperCase()
            + '. Aplikasi bisa menemukannya otomatis: membuat objek kosong sementara (tanpa menulis nilai apa pun), membaca parameternya, lalu langsung menghapusnya.',
            h('div', {}, btn));
    }

    async function apply(btn) {
        btn.disabled = true;
        btn.replaceChildren(h('span', { class: 'spinner-border spinner-border-sm me-1' }), 'Mengirim…');
        try {
            const r = await OntApp.api(cfg.configureUrl, { method: 'POST', body: payload() });
            S.jobId = r.id;
            setStep(8);
        } catch (e) {
            OntApp.toast(e.message, 'danger');
            btn.disabled = false;
            btn.replaceChildren(icon('bi-send-check'), ' APPLY CONFIGURATION');
        }
    }

    function stepMonitor() {
        const head = h('div', { class: 'mb-3' });
        const timeline = h('ul', { class: 'timeline list-unstyled mb-3' });
        const verify = h('div', {});
        const actions = h('div', { class: 'd-flex gap-2 flex-wrap' });
        const wrap = card('Step 7–9 · Apply, Monitor & Verifikasi', head, timeline, verify, actions);
        let stopped = false;

        async function poll() {
            if (stopped || !document.body.contains(wrap)) return;
            let r;
            try { r = await OntApp.api(`${cfg.apiConfig}/${S.jobId}/status`); } catch (e) { fill(head, alertBox('danger', e.message)); return setTimeout(poll, 4000); }

            const big = { success: ['success', 'bi-check-circle-fill', 'SUCCESS'], failed: ['danger', 'bi-x-circle-fill', 'FAILED'], partial: ['warning', 'bi-exclamation-triangle-fill', 'PARTIAL'] }[r.status];
            fill(head, big
                ? h('div', { class: 'result-banner text-' + big[0] }, icon(big[1]), ' ', big[2], r.is_simulation ? h('span', { class: 'fs-6 ms-2' }, badge('warning', 'SIMULASI')) : null)
                : h('div', { class: 'd-flex align-items-center gap-2' }, h('span', { class: 'spinner-border spinner-border-sm' }), h('strong', {}, r.label.toUpperCase()), r.is_simulation ? badge('warning', 'SIMULASI') : null),
                r.error_message ? alertBox(r.status === 'partial' ? 'warning' : 'danger', r.error_message) : null);

            fill(timeline, ...r.timeline.map(t => h('li', { class: 'timeline-item tl-' + t.status },
                h('span', { class: 'small text-body-secondary me-2' }, new Date(t.at).toLocaleTimeString('id-ID')), t.message)));

            if (r.verification) {
                fill(verify, h('div', { class: 'info-label mb-1' }, 'Hasil verifikasi (dibaca ulang dari ONT)'),
                    h('div', { class: 'table-responsive' }, h('table', { class: 'table table-sm small' },
                        h('thead', {}, h('tr', {}, ...['Parameter', 'Dikirim', 'Di ONT', 'Status'].map(x => h('th', {}, x)))),
                        h('tbody', {}, ...r.verification.map(v => h('tr', {}, h('td', { class: 'font-monospace text-break' }, v.path.replace('InternetGatewayDevice.', '')),
                            h('td', {}, v.status === 'secret' ? '••••' : String(v.expected)), h('td', {}, v.status === 'secret' ? '••••' : String(v.actual ?? '-')),
                            h('td', {}, badge({ match: 'success', secret: 'secondary', mismatch: 'danger', missing: 'danger' }[v.status], v.status))))))));
            }

            if (r.final) {
                stopped = true;
                fill(actions, 
                    h('a', { class: 'btn btn-outline-primary', href: cfg.historyUrl + '/' + r.id }, icon('bi-clock-history'), ' Detail riwayat'),
                    r.can_rollback ? h('button', { class: 'btn btn-outline-danger', type: 'button', onclick: (ev) => rollback(ev.currentTarget) }, icon('bi-arrow-counterclockwise'), ' Rollback') : null,
                    h('button', { class: 'btn btn-primary ms-auto', type: 'button', onclick: () => location.reload() }, icon('bi-plus-lg'), ' Konfigurasi ONT lain'));
                return;
            }
            setTimeout(poll, 2000);
        }
        setTimeout(poll, 300);
        return wrap;
    }

    async function rollback(btn) {
        if (!btn.dataset.confirm) {
            btn.dataset.confirm = '1';
            btn.replaceChildren(icon('bi-exclamation-triangle'), ' Klik lagi untuk konfirmasi rollback');
            return;
        }
        btn.disabled = true;
        try {
            const r = await OntApp.api(`${cfg.apiConfig}/${S.jobId}/rollback`, { method: 'POST' });
            OntApp.toast(r.message, r.status === 'complete' || r.status === 'simulated' ? 'success' : 'warning');
            render();
        } catch (e) {
            OntApp.toast(e.message, 'danger');
            btn.disabled = false;
        }
    }

    // ---------- mulai wizard ----------
    async function start(deviceId) {
        const holder = document.getElementById('wizardStart');
        fill(holder, h('div', { class: 'text-body-secondary small' }, h('span', { class: 'spinner-border spinner-border-sm me-1' }), 'Menyiapkan wizard…'));
        try {
            const [ctx, templates] = await Promise.all([
                OntApp.api(`${cfg.apiOnt}/${deviceId}/config-context`),
                OntApp.api(cfg.templatesUrl),
            ]);
            S = {
                step: 2, key: uuid(), ctx, templates, template_id: null, service_type: null, confirm_overwrite: false,
                wan: { mode: 'new', existing_path: null, slot: 'ip', name: '', vlan_id: null, vlan_priority: null, service_list: 'INTERNET', nat_enabled: true, pppoe_username: '', pppoe_password: '' },
                ssids: Object.fromEntries(ctx.ssids.map(s => [s.index, {
                    index: s.index, band: s.band, change: false, ssid_name: s.ssid || '', password: '',
                    security: s.beacon_type && ['11i', 'WPAand11i', 'None'].includes(s.beacon_type) ? s.beacon_type : null,
                    enabled: ['1', 'true'].includes(String(s.enabled).toLowerCase()), hidden: !!s.hidden,
                }])),
                bind: { lan: new Set(), wlan: new Set() },
            };
            const pre = cfg.prefill;
            const def = pre
                ? (templates.find(t => t.id === pre.template_id) || templates.find(t => t.is_default && t.service_type === pre.service_type) || templates.find(t => t.service_type === pre.service_type))
                : templates.find(t => t.is_default);
            fill(holder, );
            document.getElementById('deviceView').hidden = true;
            if (def) { S.service_type = def.service_type; applyTemplate(def); }
            if (pre) applyPrefill(pre, def);
            setStep(2);
        } catch (e) {
            fill(holder, alertBox('danger', e.message));
        }
    }

    document.addEventListener('ont:readiness', (ev) => {
        const holder = document.getElementById('wizardStart');
        if (!cfg.canConfigure) return;
        if (ev.detail.status === 'ready') {
            fill(holder, h('button', { class: 'btn btn-success btn-lg', type: 'button', onclick: () => start(ev.detail.id) }, icon('bi-sliders'), ' LANJUT KONFIGURASI ', icon('bi-arrow-right')));
        } else {
            fill(holder, );
        }
    });
})();
