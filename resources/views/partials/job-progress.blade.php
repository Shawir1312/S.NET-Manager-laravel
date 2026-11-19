{{-- Pop-up progres konfigurasi ONT real-time. Buka dari JS: JobProgress.open({ jobs: [{status_url, history_url}], deviceJobsUrl, expect }). --}}
<div class="modal fade" id="jobProgressModal" tabindex="-1" aria-labelledby="jobProgressTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jobProgressTitle"><span class="spinner-border spinner-border-sm me-2" id="jobProgressSpin"></span>Proses Setting ONT</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body" id="jobProgressBody" aria-live="polite"></div>
            <div class="modal-footer"><small class="text-body-secondary me-auto" id="jobProgressHint">Boleh ditutup — proses tetap berjalan di server.</small>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="jobProgressClose">Tutup</button>
                <a class="btn btn-primary" id="jobProgressNext" style="display:none;"><i class="bi bi-plus-circle me-1"></i>Tambah Pelanggan Lain</a></div>
        </div>
    </div>
</div>
<script>
window.JobProgress = (() => {
    const ICON = { success: 'bi-check-circle-fill text-success', warning: 'bi-exclamation-triangle-fill text-warning', failed: 'bi-x-circle-fill text-danger', error: 'bi-x-circle-fill text-danger' };
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let timer = null, run = 0;

    function render(jobs, states, waiting) {
        const body = document.getElementById('jobProgressBody');
        body.innerHTML = jobs.map((j, i) => {
            const s = states[j.status_url];
            if (!s) return `<div class="mb-3"><span class="spinner-border spinner-border-sm me-2"></span>Menunggu antrean…</div>`;
            const steps = (s.timeline || []).map((t, k, all) => {
                const live = !s.final && k === all.length - 1;
                const icon = live ? '<span class="spinner-border spinner-border-sm text-primary"></span>' : `<i class="bi ${ICON[t.status] || 'bi-dot text-body-secondary'}"></i>`;
                return `<li class="d-flex gap-2 mb-1">${icon}<span><span class="text-body-secondary small">${esc((t.at || '').substring(11, 19))}</span> ${esc(t.message || t.event)}</span></li>`;
            }).join('');
            return `<div class="mb-3 ${i ? 'pt-3 border-top' : ''}">
                <div class="d-flex align-items-center gap-2 mb-2"><strong>${esc(j.label || 'Konfigurasi')}</strong>
                    <span class="badge bg-${esc(s.badge)}">${esc(s.label)}</span>${s.is_simulation ? '<span class="badge bg-warning text-dark">SIMULASI</span>' : ''}</div>
                ${s.error_message ? `<div class="alert alert-${s.status === 'partial' ? 'warning' : 'danger'} py-2 small">${esc(s.error_message)}</div>` : ''}
                <ul class="list-unstyled small mb-2">${steps || '<li class="text-body-secondary">Memulai…</li>'}</ul>
                ${j.history_url ? `<a class="small" href="${esc(j.history_url)}">Detail di Riwayat Config ONT</a>` : ''}</div>`;
        }).join('') + (waiting ? '<div class="small text-body-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Menunggu konfigurasi berikutnya (hotspot) masuk antrean…</div>' : '');
    }

    // opts.jobs: job yang sudah diketahui; opts.deviceJobsUrl: cari job baru di ONT ini (mis. hotspot yang diantrekan); opts.expect: jumlah job yang ditunggu.
    // opts.doneUrl: tombol "Tambah Pelanggan Lain" setelah selesai; opts.goOnClose: menutup pop-up setelah selesai = kembali ke doneUrl.
    function open(opts) {
        const jobs = [...(opts.jobs || [])], states = {}, me = ++run, started = Date.now();
        const modal = document.getElementById('jobProgressModal'), next = document.getElementById('jobProgressNext');
        let lastFinal = null, done = false;
        clearTimeout(timer);
        document.getElementById('jobProgressSpin').style.display = '';
        document.getElementById('jobProgressHint').textContent = 'Boleh ditutup — proses tetap berjalan di server.';
        next.style.display = 'none';
        modal.addEventListener('hidden.bs.modal', () => { if (done && opts.goOnClose && opts.doneUrl && me === run) location.href = opts.doneUrl; }, { once: true });
        bootstrap.Modal.getOrCreateInstance(modal).show();
        render(jobs, states, false);

        const tick = async () => {
            if (me !== run) return;
            try {
                if (opts.deviceJobsUrl) {
                    for (const j of await OntApp.api(opts.deviceJobsUrl)) {
                        if (!jobs.some(x => x.status_url === j.status_url)) jobs.push(j);
                    }
                }
                await Promise.all(jobs.map(async j => { if (!states[j.status_url]?.final) states[j.status_url] = await OntApp.api(j.status_url); }));
            } catch (e) { /* coba lagi */ }
            const allFinal = jobs.length > 0 && jobs.every(j => states[j.status_url]?.final);
            if (allFinal && lastFinal === null) lastFinal = Date.now();
            if (!allFinal) lastFinal = null;
            const expectMore = jobs.length < (opts.expect || 1) && (lastFinal === null || Date.now() - lastFinal < 25000) && Date.now() - started < 600000;
            render(jobs, states, allFinal && expectMore);
            if (allFinal && !expectMore) {
                document.getElementById('jobProgressSpin').style.display = 'none';
                done = true;
                if (opts.doneUrl) {
                    next.href = opts.doneUrl;
                    next.style.display = '';
                    document.getElementById('jobProgressHint').textContent = 'Setting selesai.';
                }
                opts.onDone?.(jobs.map(j => states[j.status_url]));
                return;
            }
            timer = setTimeout(tick, 2000);
        };
        tick();
    }

    return { open };
})();
@if ($progress = session('job_progress'))
document.addEventListener('DOMContentLoaded', () => JobProgress.open(@json($progress)));
@endif
</script>
