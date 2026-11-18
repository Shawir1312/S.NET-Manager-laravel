/* ONT AUTO CONFIGURATION — helper frontend (tema, fetch API, toast, chart). */
window.OntApp = (function () {
    'use strict';

    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    // ---------- Tema ----------
    function setTheme(theme) {
        document.documentElement.setAttribute('data-bs-theme', theme);
        try { localStorage.setItem('ont-theme', theme); } catch (e) { /* abaikan */ }
        document.dispatchEvent(new CustomEvent('ont:theme', { detail: theme }));
    }
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        setTheme(document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark');
    });

    // ---------- Fetch API (cookie sesi + CSRF) ----------
    async function api(url, options = {}) {
        const opts = {
            method: options.method || 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        };
        if (options.body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(options.body);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch (e) {
            throw new Error('Tidak dapat terhubung ke server aplikasi.');
        }

        let data = null;
        try { data = await res.json(); } catch (e) { /* respons bukan JSON */ }

        if (res.status === 401) { window.location.href = '/login'; throw new Error('Sesi berakhir, silakan login ulang.'); }
        if (res.status === 419) throw new Error('Sesi kedaluwarsa. Muat ulang halaman.');
        if (res.status === 429) throw new Error('Terlalu banyak permintaan. Tunggu sebentar lalu coba lagi.');
        if (!res.ok && !(data && 'ok' in data)) {
            const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
            throw new Error(first || data?.message || ('Permintaan gagal (HTTP ' + res.status + ').'));
        }
        return data;
    }

    // ---------- Toast ----------
    function toast(message, tone = 'primary') {
        const area = document.getElementById('toastArea');
        if (!area || !window.bootstrap) return;
        const el = document.createElement('div');
        el.className = 'toast align-items-center text-bg-' + tone + ' border-0';
        el.setAttribute('role', 'status');
        const wrap = document.createElement('div');
        wrap.className = 'd-flex';
        const body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message; // textContent: aman dari XSS
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'Tutup');
        wrap.append(body, close);
        el.append(wrap);
        area.append(el);
        const t = new bootstrap.Toast(el, { delay: 5000 });
        el.addEventListener('hidden.bs.toast', () => el.remove());
        t.show();
    }

    // ---------- Chart dashboard ----------
    const charts = [];
    function chartColors() {
        const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        return { text: dark ? '#b8c4d6' : '#5b6474', grid: dark ? 'rgba(255,255,255,.07)' : 'rgba(15,27,45,.07)' };
    }

    function renderDashboardCharts(s) {
        if (!window.Chart) return;
        charts.splice(0).forEach(c => c.destroy());
        const c = chartColors();
        Chart.defaults.color = c.text;
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;

        const empty = (arr) => arr.every(v => v === 0);
        const doughnutOpts = { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom' } } };

        charts.push(new Chart(document.getElementById('chartActivity'), {
            type: 'bar',
            data: { labels: s.activity.labels, datasets: [
                { label: 'Berhasil', data: s.activity.success, backgroundColor: '#22a06b', borderRadius: 4 },
                { label: 'Gagal / Sebagian', data: s.activity.failed, backgroundColor: '#e5484d', borderRadius: 4 },
            ] },
            options: { responsive: true, maintainAspectRatio: false,
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid: { color: c.grid } } },
                plugins: { legend: { position: 'bottom' } } },
        }));

        const vendorData = [s.vendors.fiberhome.total, s.vendors.zte.total, s.vendors.huawei.total, s.vendors.other.total];
        charts.push(new Chart(document.getElementById('chartVendor'), {
            type: 'doughnut',
            data: { labels: ['FiberHome', 'ZTE', 'Huawei', 'Lainnya'],
                datasets: [{ data: empty(vendorData) ? [1] : vendorData, backgroundColor: empty(vendorData) ? [c.grid] : ['#1d6ef5', '#f5a524', '#e5484d', '#8b95a7'], borderWidth: 0 }] },
            options: { ...doughnutOpts, plugins: { ...doughnutOpts.plugins, legend: { display: !empty(vendorData), position: 'bottom' }, tooltip: { enabled: !empty(vendorData) } } },
        }));

        const onlineData = [s.devices.online, s.devices.offline];
        charts.push(new Chart(document.getElementById('chartOnline'), {
            type: 'doughnut',
            data: { labels: ['Online', 'Offline'],
                datasets: [{ data: empty(onlineData) ? [1] : onlineData, backgroundColor: empty(onlineData) ? [c.grid] : ['#22a06b', '#e5484d'], borderWidth: 0 }] },
            options: { ...doughnutOpts, plugins: { ...doughnutOpts.plugins, legend: { display: !empty(onlineData), position: 'bottom' }, tooltip: { enabled: !empty(onlineData) } } },
        }));

        document.addEventListener('ont:theme', () => renderDashboardCharts(s), { once: true });
    }

    // Form hapus: klik pertama minta konfirmasi, klik kedua mengirim.
    document.addEventListener('submit', (e) => {
        const f = e.target;
        if (!f.classList?.contains('confirm-delete') || f.dataset.confirmed) return;
        e.preventDefault();
        f.dataset.confirmed = '1';
        const b = f.querySelector('button') || document.querySelector(`button[form="${f.id}"]`);
        if (b) { b.classList.replace('btn-outline-danger', 'btn-danger'); b.title = 'Klik lagi untuk menghapus'; }
        toast('Klik tombol hapus sekali lagi untuk konfirmasi.', 'warning');
        setTimeout(() => { delete f.dataset.confirmed; b?.classList.replace('btn-danger', 'btn-outline-danger'); }, 5000);
    });

    return { api, toast, setTheme, renderDashboardCharts };
})();
