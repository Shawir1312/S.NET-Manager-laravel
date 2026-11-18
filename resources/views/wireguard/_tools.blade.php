<script>
const WG_TOOLS = @json(route('wireguard.tools'));
function wgTool(action, params = {}, post = false) {
    const headers = {'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content};
    return (post
        ? fetch(WG_TOOLS, {method: 'POST', headers: {...headers, 'Content-Type': 'application/json'}, body: JSON.stringify({action, ...params})})
        : fetch(WG_TOOLS + '?' + new URLSearchParams({action, ...params}), {headers})
    ).then(r => r.json());
}
function wgSyncAll(btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Menyinkronkan...';
    wgTool('sync_all_peers', {}, true)
        .then(d => alert(d.success ? '✓ Sukses! ' + (d.count || 0) + ' router peer dan konfigurasi NAT berhasil disinkronkan ke WireGuard.'
            : 'Sinkronisasi selesai dengan peringatan: ' + ((d.errors || []).join('\n') || 'Cek status service.')))
        .catch(err => alert('Gagal menghubungi server untuk sinkronisasi: ' + err.message))
        .finally(() => location.reload());
}
</script>
