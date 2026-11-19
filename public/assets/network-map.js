// Peta jaringan S.NET: server → ODP → pelanggan. Garis hijau beranimasi = terhubung, merah = putus.
// Dipakai halaman admin (editable) dan halaman publik tanpa login (read-only).
window.NetworkMap = (() => {
    const COLORS = { online: '#16a34a', offline: '#dc2626', isolated: '#6b7280', up: '#16a34a', down: '#dc2626', empty: '#9ca3af' };
    const LABEL = { online: 'Terhubung', offline: 'Putus / Offline', isolated: 'Isolir', up: 'Normal', down: 'Semua pelanggan putus', empty: 'Belum ada pelanggan' };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function icon(kind, status) {
        const color = COLORS[status] || '#2563eb';
        if (kind === 'server') return L.divIcon({ className: '', iconSize: [34, 34], iconAnchor: [17, 17], html: `<div class="nm-node nm-server"><i class="bi bi-hdd-rack-fill"></i></div>` });
        if (kind === 'odp') return L.divIcon({ className: '', iconSize: [26, 26], iconAnchor: [13, 13], html: `<div class="nm-node nm-odp" style="--c:${color}"><i class="bi bi-diagram-3-fill"></i></div>` });
        return L.divIcon({ className: '', iconSize: [16, 16], iconAnchor: [8, 8], html: `<div class="nm-dot ${status === 'offline' ? 'nm-blink' : ''}" style="--c:${color}"></div>` });
    }

    /**
     * opts: { el, dataUrl, editable, urls: {odpStore, odpUpdate, odpDestroy, customerUpdate, serverUpdate} (pakai __ID__), routerId,
     *         onData(data), listEl }
     */
    function init(opts) {
        const map = L.map(opts.el, { zoomControl: true }).setView([-2.5, 118], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20, attribution: '&copy; OpenStreetMap' }).addTo(map);
        const layer = L.layerGroup().addTo(map);
        let data = null, fitted = false, addMode = null, timer = null;
        const markers = { customer: {}, odp: {} };

        const send = (url, method, body) => OntApp.api(url, { method, body });
        const url = (k, id) => opts.urls[k].replace('__ID__', id);

        function line(from, to, status, tip) {
            if (!from || !to || from.lat == null || to.lat == null) return;
            L.polyline([[from.lat, from.lng], [to.lat, to.lng]], { color: COLORS[status] || '#9ca3af', weight: status === 'up' || status === 'online' ? 3 : 2.5, className: 'nm-line nm-' + status, interactive: !!tip })
                .bindTooltip(tip || '', { sticky: true }).addTo(layer);
        }

        function render() {
            layer.clearLayers();
            markers.customer = {}; markers.odp = {};
            const s = data.server, hasServer = s.lat != null;
            const odpById = Object.fromEntries(data.odps.map(o => [o.id, o]));

            // Garis dulu (di bawah marker): pelanggan → ODP (atau langsung server), ODP → server.
            data.customers.forEach(c => line(c, odpById[c.odp_id] || (hasServer ? s : null), c.status, `${esc(c.name)} — ${LABEL[c.status]}`));
            if (hasServer) data.odps.forEach(o => line(o, s, o.status, `${esc(o.name)}: ${o.online}/${o.total} online`));

            if (hasServer) {
                const m = L.marker([s.lat, s.lng], { icon: icon('server'), draggable: !!opts.editable, zIndexOffset: 1000 })
                    .bindPopup(`<strong>${esc(s.name)}</strong><br><small>Server / cabang</small>`).addTo(layer);
                m.on('dragend', () => save(url('serverUpdate', s.id), 'PUT', m.getLatLng()));
            }
            data.odps.forEach(o => {
                const m = L.marker([o.lat, o.lng], { icon: icon('odp', o.status), draggable: !!opts.editable, zIndexOffset: 500 }).addTo(layer);
                m.bindPopup(() => odpPopup(o));
                m.on('dragend', () => save(url('odpUpdate', o.id), 'PUT', m.getLatLng()));
                markers.odp[o.id] = m;
            });
            data.customers.forEach(c => {
                const m = L.marker([c.lat, c.lng], { icon: icon('customer', c.status), draggable: !!opts.editable }).addTo(layer);
                m.bindPopup(() => customerPopup(c));
                m.on('dragend', () => save(url('customerUpdate', c.id), 'PUT', m.getLatLng()));
                markers.customer[c.id] = m;
            });

            if (!fitted) {
                const pts = [...data.customers, ...data.odps, ...(hasServer ? [s] : [])].map(p => [p.lat, p.lng]);
                if (pts.length) map.fitBounds(pts, { padding: [40, 40], maxZoom: 17 });
                fitted = true;
            }
            renderList();
            opts.onData?.(data);
        }

        function customerPopup(c) {
            const odp = data.odps.find(o => o.id === c.odp_id);
            let html = `<strong>${esc(c.name)}</strong><br><span style="color:${COLORS[c.status]}">● ${LABEL[c.status]}</span>`
                + (c.username ? `<br><small class="font-monospace">${esc(c.username)}</small>` : '')
                + `<br><small>ODP: ${odp ? esc(odp.name) : '— (langsung ke server)'}</small>`;
            if (opts.editable) {
                html += `<div class="mt-2"><select class="form-select form-select-sm" data-odp-for="${c.id}"><option value="">— tanpa ODP —</option>`
                    + data.odps.map(o => `<option value="${o.id}" ${o.id === c.odp_id ? 'selected' : ''}>${esc(o.name)}</option>`).join('') + `</select>`
                    + `<a class="btn btn-sm btn-link px-0" href="${esc(data.customer_url.replace('__ID__', c.id))}">Buka data pelanggan</a></div>`;
            }
            const div = document.createElement('div');
            div.innerHTML = html;
            div.querySelector('[data-odp-for]')?.addEventListener('change', e => save(url('customerUpdate', c.id), 'PUT', { odp_id: e.target.value || null }));
            return div;
        }

        function odpPopup(o) {
            const div = document.createElement('div');
            div.innerHTML = `<strong>${esc(o.name)}</strong><br><span style="color:${COLORS[o.status]}">● ${LABEL[o.status]}</span><br><small>${o.online}/${o.total} pelanggan online${o.capacity ? ' · kapasitas ' + o.capacity : ''}</small>`
                + (opts.editable ? `<div class="mt-2 d-flex gap-1"><button class="btn btn-sm btn-outline-secondary" data-act="rename">Ubah nama</button><button class="btn btn-sm btn-outline-danger" data-act="delete">Hapus</button></div>` : '');
            div.querySelector('[data-act=rename]')?.addEventListener('click', () => {
                const name = prompt('Nama ODP', o.name);
                if (name) save(url('odpUpdate', o.id), 'PUT', { name });
            });
            div.querySelector('[data-act=delete]')?.addEventListener('click', () => {
                if (confirm(`Hapus ${o.name}? Pelanggan di ODP ini akan langsung terhubung ke server di peta.`)) save(url('odpDestroy', o.id), 'DELETE');
            });
            return div;
        }

        // Daftar pelanggan bermasalah (offline) + ODP putus, klik untuk zoom.
        function renderList() {
            if (!opts.listEl) return;
            const bad = data.customers.filter(c => c.status === 'offline');
            const downOdps = data.odps.filter(o => o.status === 'down');
            opts.listEl.innerHTML = (downOdps.map(o => `<button type="button" class="list-group-item list-group-item-action list-group-item-danger" data-zoom-odp="${o.id}"><i class="bi bi-diagram-3-fill me-1"></i><strong>${esc(o.name)}</strong> — semua pelanggan putus (${o.total})</button>`).join('')
                + bad.map(c => `<button type="button" class="list-group-item list-group-item-action" data-zoom-c="${c.id}"><span class="nm-dot d-inline-block me-2" style="--c:${COLORS.offline}"></span>${esc(c.name)}<small class="text-body-secondary ms-1">${esc(data.odps.find(o => o.id === c.odp_id)?.name || '')}</small></button>`).join(''))
                || '<div class="list-group-item text-success"><i class="bi bi-check-circle me-1"></i>Semua pelanggan terhubung.</div>';
            opts.listEl.querySelectorAll('[data-zoom-c]').forEach(b => b.addEventListener('click', () => focus(markers.customer[b.dataset.zoomC])));
            opts.listEl.querySelectorAll('[data-zoom-odp]').forEach(b => b.addEventListener('click', () => focus(markers.odp[b.dataset.zoomOdp])));
        }

        function focus(m) { if (m) { map.setView(m.getLatLng(), Math.max(map.getZoom(), 18)); m.openPopup(); } }

        async function save(u, method, body) {
            try {
                if (body instanceof L.LatLng) body = { lat: body.lat, lng: body.lng }; // dari geser marker
                await send(u, method, body);
                load();
            } catch (e) { OntApp.toast(e.message, 'danger'); load(); }
        }

        async function load() {
            clearTimeout(timer);
            try {
                data = await OntApp.api(opts.dataUrl);
                render();
            } catch (e) { OntApp.toast('Gagal memuat peta: ' + e.message, 'danger'); }
            timer = setTimeout(load, 30000);
        }

        // Mode klik-di-peta: tambah ODP / tempatkan pelanggan.
        map.on('click', async e => {
            if (!addMode) return;
            const mode = addMode;
            addMode = null;
            map.getContainer().style.cursor = '';
            if (mode.type === 'odp') {
                const name = prompt('Nama ODP baru', 'ODP-' + String((data?.odps.length || 0) + 1).padStart(2, '0'));
                if (name) save(opts.urls.odpStore, 'POST', { router_id: opts.routerId, name, lat: e.latlng.lat, lng: e.latlng.lng });
            } else if (mode.type === 'customer') {
                save(url('customerUpdate', mode.id), 'PUT', { lat: e.latlng.lat, lng: e.latlng.lng });
            } else if (mode.type === 'server') {
                save(url('serverUpdate', opts.routerId), 'PUT', { lat: e.latlng.lat, lng: e.latlng.lng });
            }
            mode.done?.();
        });

        function pick(mode) {
            addMode = mode;
            map.getContainer().style.cursor = 'crosshair';
            OntApp.toast('Klik lokasi di peta.', 'info');
        }

        load();
        return { reload: load, pick, map };
    }

    return { init, COLORS, LABEL };
})();
