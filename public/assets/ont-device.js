/* ONT AUTO CONFIGURATION — pencarian SN & tampilan detail ONT. Semua teks dirender dengan textContent (anti-XSS). */
window.OntDevice = (function () {
    'use strict';

    // ---------- helper DOM ----------
    function h(tag, attrs = {}, ...children) {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k === 'text') el.textContent = v;
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const c of children.flat()) {
            if (c === null || c === undefined || c === false) continue;
            el.append(c instanceof Node ? c : document.createTextNode(String(c)));
        }
        return el;
    }
    const badge = (tone, text) => h('span', { class: 'badge text-bg-' + tone }, text);
    const icon = (name) => h('i', { class: 'bi ' + name });
    const fmtDate = (iso) => iso ? new Date(iso).toLocaleString('id-ID') : '-';
    const ago = (iso) => {
        if (!iso) return '-';
        const s = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
        if (s < 60) return s + ' detik lalu';
        if (s < 3600) return Math.round(s / 60) + ' menit lalu';
        if (s < 86400) return Math.round(s / 3600) + ' jam lalu';
        return Math.round(s / 86400) + ' hari lalu';
    };
    const uptime = (sec) => {
        sec = Number(sec);
        if (!Number.isFinite(sec)) return '-';
        const d = Math.floor(sec / 86400), hh = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60);
        return (d ? d + 'h ' : '') + hh + 'j ' + m + 'm';
    };
    const bool = (v) => v === null || v === undefined ? null : ['1', 'true'].includes(String(v).toLowerCase());
    const yesNo = (v) => { const b = bool(v); return b === null ? badge('secondary', '?') : (b ? badge('success', 'Aktif') : badge('secondary', 'Nonaktif')); };

    function statusBox(el, tone, iconName, title, message, spinner = false) {
        el.replaceChildren(h('div', { class: 'alert alert-' + tone + ' d-flex gap-2 align-items-start mb-0' },
            spinner ? h('span', { class: 'spinner-border spinner-border-sm mt-1' }) : icon(iconName + ' fs-5'),
            h('div', {}, h('div', { class: 'fw-semibold' }, title), message ? h('div', { class: 'small' }, message) : null)));
    }

    // ---------- kartu info ONT ----------
    function infoCard(info, detection) {
        const row = (label, value) => h('div', { class: 'col-6 col-md-4 col-xl-3' },
            h('div', { class: 'info-label' }, label),
            h('div', { class: 'info-value' }, value === null || value === undefined || value === '' ? '-' : value));

        const modelBadge = detection.supported
            ? badge(detection.simulation_only ? 'warning' : 'success', detection.simulation_only ? 'Uji coba (simulasi)' : 'Didukung')
            : badge('danger', 'Unsupported');

        const caps = info.capabilities;
        const capList = h('div', { class: 'd-flex flex-wrap gap-1' },
            badge(caps.supports_bridge ? 'success' : 'secondary', 'Bridge ' + (caps.supports_bridge ? '✓' : 'belum diverifikasi')),
            badge(caps.supports_pppoe ? 'success' : 'secondary', 'PPPoE ' + (caps.supports_pppoe ? '✓' : 'belum diverifikasi')),
            caps.has_5g === null ? null : badge('info', caps.has_5g ? 'Dual band 2.4/5 GHz' : '2.4 GHz'),
            badge('light', 'Binding: ' + caps.binding_method));

        const card = h('div', { class: 'card mb-3' },
            h('div', { class: 'card-header d-flex flex-wrap gap-2 align-items-center' },
                h('span', {}, 'Informasi ONT'), modelBadge,
                info.completeness.complete ? null : badge('warning', 'Data belum lengkap: ' + info.completeness.missing.join(', '))),
            h('div', { class: 'card-body' },
                h('div', { class: 'row g-3' },
                    row('Serial Number', h('span', { class: 'font-monospace' }, info.serial_number)),
                    row('Manufacturer', detection.vendor_label),
                    row('Product Class', info.product_class),
                    row('Model', info.model_name),
                    row('Hardware Version', info.hardware_version),
                    row('Software Version', info.software_version),
                    row('IP Address (WAN)', info.ip_address),
                    row('IP TR-069 (Conn. Request)', info.tr069_ip),
                    row('Last Inform', h('span', { title: fmtDate(info.last_inform) }, ago(info.last_inform))),
                    row('Uptime', uptime(info.uptime_seconds)),
                    row('Jumlah WAN', `${info.counts.wan} (IP: ${info.counts.wan_ip}, PPP: ${info.counts.wan_ppp})`),
                    row('Jumlah LAN / SSID', `${info.counts.lan} LAN · ${info.counts.ssid} SSID`),
                ),
                h('hr'),
                h('div', { class: 'info-label mb-1' }, 'Kemampuan Perangkat'), capList,
                h('div', { class: 'small text-body-secondary mt-2' }, detection.message)));
        return card;
    }

    function wanTable(wans) {
        const body = wans.length ? wans.map(w => h('tr', {},
            h('td', { class: 'font-monospace small' }, w.path.replace('InternetGatewayDevice.', '')),
            h('td', {}, w.slot.toUpperCase()),
            h('td', {}, w.name || '-'),
            h('td', {}, w.connection_type || '-'),
            h('td', {}, w.status || '-'),
            h('td', { class: 'font-monospace small' }, w.external_ip || '-'),
            h('td', {}, w.username || '-'))) : [h('tr', {}, h('td', { colspan: 7, class: 'text-body-secondary' }, 'Belum ada WAN terbaca.'))];
        return h('div', { class: 'card mb-3' }, h('div', { class: 'card-header' }, 'WAN Connections'),
            h('div', { class: 'table-responsive' }, h('table', { class: 'table table-sm align-middle mb-0' },
                h('thead', {}, h('tr', {}, ...['Path', 'Slot', 'Nama', 'Mode', 'Status', 'IP', 'User PPPoE'].map(t => h('th', {}, t)))),
                h('tbody', {}, body))));
    }

    function ssidTable(ssids) {
        const body = ssids.length ? ssids.map(s => h('tr', {},
            h('td', {}, 'SSID ' + s.index),
            h('td', {}, s.ssid || '-'),
            h('td', {}, s.band || '-'),
            h('td', {}, yesNo(s.enabled)),
            h('td', {}, s.beacon_type || '-'),
            h('td', {}, s.hidden === null ? '-' : (s.hidden ? 'Ya' : 'Tidak')),
            h('td', {}, s.channel === null || s.channel === undefined ? '-' : (String(s.channel) === '0' ? 'Auto' : s.channel)),
            h('td', {}, s.clients ?? '-'))) : [h('tr', {}, h('td', { colspan: 8, class: 'text-body-secondary' }, 'Belum ada SSID terbaca.'))];
        return h('div', { class: 'card mb-3' }, h('div', { class: 'card-header' }, 'WiFi / SSID'),
            h('div', { class: 'table-responsive' }, h('table', { class: 'table table-sm align-middle mb-0' },
                h('thead', {}, h('tr', {}, ...['SSID', 'Nama', 'Band', 'Status', 'Keamanan', 'Hidden', 'Channel', 'Klien'].map(t => h('th', {}, t)))),
                h('tbody', {}, body))));
    }

    async function loadDevice(cfg, id, view, statusEl, { checkOnline = true } = {}) {
        statusEl.hidden = false;
        statusEl.className = 'mb-3';
        statusEl.dataset.state = 'loading';
        statusEl.replaceChildren();
        statusBox(statusEl, 'info', '', 'Membaca data ONT dari GenieACS…', null, true);

        let data;
        try {
            data = await OntApp.api(`${cfg.showUrl}/${id}`);
        } catch (e) {
            statusBox(statusEl, 'danger', 'bi-x-octagon', 'Gagal membaca data ONT', e.message);
            return;
        }

        view.hidden = false;
        if (data.detection.auto_registered) OntApp.toast('Model ' + data.info.product_class + ' didaftarkan otomatis ke Device Models.', 'info');
        if ((data.detection.auto_verified || []).length) OntApp.toast(data.detection.auto_verified.length + ' parameter terverifikasi otomatis dari ONT ini dan disimpan di Device Models.', 'success');
        const actions = h('div', { class: 'd-flex flex-wrap gap-2 mb-3' });
        view.replaceChildren(actions, infoCard(data.info, data.detection), wanTable(data.info.wan_connections), ssidTable(data.info.ssids));

        const btnOnline = h('button', { class: 'btn btn-outline-primary', type: 'button' }, icon('bi-broadcast'), ' Cek Online');
        actions.append(btnOnline);
        if (cfg.canConfigure) {
            const btnRefresh = h('button', { class: 'btn btn-outline-secondary', type: 'button' }, icon('bi-arrow-clockwise'), ' Refresh Parameter dari ONT');
            btnRefresh.addEventListener('click', () => refresh(cfg, id, view, statusEl, btnRefresh));
            actions.append(btnRefresh);
        }
        if (cfg.deviceUrl) {
            actions.append(h('a', { class: 'btn btn-link', href: `${cfg.deviceUrl}/${id}` }, 'Lihat semua parameter ', icon('bi-box-arrow-up-right')));
        }
        btnOnline.addEventListener('click', () => readiness(cfg, id, statusEl, btnOnline));

        if (checkOnline) await readiness(cfg, id, statusEl, btnOnline);
        else statusBox(statusEl, 'secondary', 'bi-info-circle', 'Data dari cache GenieACS', 'Klik "Cek Online" untuk memeriksa status ONT saat ini.');
    }

    async function readiness(cfg, id, statusEl, btn) {
        btn.disabled = true;
        statusBox(statusEl, 'info', '', 'Memeriksa status online (Connection Request ke ONT)…', 'Bisa memakan waktu hingga beberapa detik bila ONT tidak merespons.', true);
        try {
            const r = await OntApp.api(`${cfg.showUrl}/${id}/check-online`, { method: 'POST' });
            const tone = { ready: 'success', offline: 'danger', unsupported: 'warning' }[r.status] || 'info';
            const ic = { ready: 'bi-check-circle', offline: 'bi-wifi-off', unsupported: 'bi-exclamation-triangle' }[r.status] || 'bi-info-circle';
            const title = r.label + (r.status === 'ready' && r.simulation_only ? ' (mode simulasi)' : '');
            statusBox(statusEl, tone, ic, title, r.message + ' · dicek ' + fmtDate(r.checked_at));
            statusEl.dataset.state = r.status;
            document.dispatchEvent(new CustomEvent('ont:readiness', { detail: { id, ...r } }));
        } catch (e) {
            statusBox(statusEl, 'danger', 'bi-x-octagon', 'Gagal memeriksa status', e.message);
        } finally {
            btn.disabled = false;
        }
    }

    async function refresh(cfg, id, view, statusEl, btn) {
        btn.disabled = true;
        statusBox(statusEl, 'info', '', 'Membaca ulang parameter dari ONT…', 'DeviceInfo → WANDevice → LANDevice. Bisa memakan waktu hingga 1–2 menit.', true);
        try {
            const r = await OntApp.api(`${cfg.showUrl}/${id}/refresh`, { method: 'POST' });
            const last = r.results[r.results.length - 1];
            OntApp.toast(r.completed ? 'Parameter berhasil dibaca ulang dari ONT.' : last.message, r.completed ? 'success' : 'warning');
            await loadDevice(cfg, id, view, statusEl, { checkOnline: false });
            if (!r.completed) statusBox(statusEl, 'warning', 'bi-hourglass-split', 'Refresh belum selesai', last.message);
        } catch (e) {
            statusBox(statusEl, 'danger', 'bi-x-octagon', 'Refresh gagal', e.message);
        } finally {
            btn.disabled = false;
        }
    }

    // ---------- halaman Auto Configuration ----------
    function initAutoConfig(cfg) {
        const form = document.getElementById('searchForm');
        const input = document.getElementById('sn');
        const err = document.getElementById('snError');
        const btn = document.getElementById('btnSearch');
        const statusEl = document.getElementById('searchStatus');
        const chooser = document.getElementById('deviceChooser');
        const view = document.getElementById('deviceView');

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const sn = input.value.trim().toUpperCase().replace(/\s+/g, '');
            input.classList.remove('is-invalid');
            if (!/^[A-Z0-9][A-Z0-9\-_.]{3,63}$/.test(sn)) {
                err.textContent = 'Masukkan Serial Number yang valid (min. 4 karakter, huruf/angka).';
                input.classList.add('is-invalid');
                return;
            }

            chooser.hidden = true; view.hidden = true; view.replaceChildren();
            btn.disabled = true;
            statusBox(statusEl, 'info', '', 'Searching…', 'Mencari SN ' + sn + ' di GenieACS.', true);

            let r;
            try {
                r = await OntApp.api(cfg.searchUrl, { method: 'POST', body: { serial_number: sn, genie_server_id: cfg.prefill?.genie_server_id ?? null } });
            } catch (e) {
                statusBox(statusEl, 'danger', 'bi-x-octagon', 'Pencarian gagal', e.message);
                btn.disabled = false;
                return;
            }
            btn.disabled = false;

            if (r.status === 'not_found') {
                statusBox(statusEl, 'danger', 'bi-search', r.label, r.message);
                return;
            }
            if (r.status === 'multiple') {
                statusBox(statusEl, 'warning', 'bi-exclamation-triangle', r.label, r.message);
                chooser.hidden = false;
                chooser.replaceChildren(h('div', { class: 'list-group' }, ...r.devices.map(d =>
                    h('button', { type: 'button', class: 'list-group-item list-group-item-action', onclick: () => {
                        chooser.hidden = true;
                        loadDevice(cfg, d.id, view, statusEl);
                    } },
                        h('div', { class: 'fw-semibold font-monospace' }, d.genieacs_id),
                        h('div', { class: 'small text-body-secondary' }, `${d.detection.vendor_label} ${d.product_class || ''} · SN ${d.serial_number} · inform ${ago(d.last_inform)}`)))));
                return;
            }
            await loadDevice(cfg, r.devices[0].id, view, statusEl);
        });
        // Config manual dari halaman pelanggan: SN sudah diketahui -> langsung cari.
        if (cfg.prefill?.sn) {
            input.value = cfg.prefill.sn;
            form.requestSubmit();
        }
    }

    // ---------- halaman detail ONT ----------
    function initDetail(cfg) {
        const statusEl = document.getElementById('searchStatus');
        const view = document.getElementById('deviceView');
        loadDevice(cfg, cfg.id, view, statusEl, { checkOnline: false });

        const btn = document.getElementById('btnLoadParams');
        const box = document.getElementById('paramBox');
        const rowsEl = document.getElementById('paramRows');
        const filter = document.getElementById('paramFilter');
        const infoEl = document.getElementById('paramInfo');
        let params = [];
        const LIMIT = 500;

        function render() {
            const q = filter.value.trim().toLowerCase();
            const matched = q ? params.filter(p => p.path.toLowerCase().includes(q) || String(p.value ?? '').toLowerCase().includes(q)) : params;
            rowsEl.replaceChildren(...matched.slice(0, LIMIT).map(p => h('tr', {},
                h('td', { class: 'text-break' }, p.path),
                h('td', { class: 'text-break' }, p.secret ? h('span', { class: 'text-body-secondary', title: 'Disamarkan' }, '••••••') : String(p.value ?? '')),
                h('td', {}, (p.type || '').replace('xsd:', '')),
                h('td', {}, p.writable ? 'RW' : 'R'))));
            infoEl.textContent = `${matched.length} dari ${params.length} parameter` + (matched.length > LIMIT ? ` (ditampilkan ${LIMIT} pertama, persempit filter)` : '') + '. Nilai rahasia (password, passphrase) selalu disamarkan.';
        }

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.replaceChildren(h('span', { class: 'spinner-border spinner-border-sm me-1' }), 'Memuat…');
            try {
                const r = await OntApp.api(`${cfg.showUrl}/${cfg.id}/parameters`);
                params = r.parameters;
                document.getElementById('paramCount').textContent = r.count;
                box.hidden = false;
                render();
                btn.replaceChildren(icon('bi-arrow-repeat'), ' Muat ulang');
            } catch (e) {
                OntApp.toast(e.message, 'danger');
                btn.replaceChildren(icon('bi-list-ul'), ' Tampilkan parameter');
            } finally {
                btn.disabled = false;
            }
        });
        let t;
        filter.addEventListener('input', () => { clearTimeout(t); t = setTimeout(render, 150); });
    }

    return { initAutoConfig, initDetail, h, badge, icon, fmtDate, statusBox };
})();
