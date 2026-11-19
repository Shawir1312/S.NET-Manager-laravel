// Scan SN ONT dari QR/barcode label (butuh html5-qrcode). Dipakai Setting ONT Hotspot & form pelanggan.
window.SnScan = (() => {
    let scanner = null;

    // GPON SN hex 16 karakter (mis. label Huawei 4857544379FC699D) → bentuk PON di ACS (HWTC79FC699D).
    function hexToPon(h) {
        if (!/^[0-9A-F]{16}$/.test(h)) return null;
        const vendor = h.slice(0, 8).match(/../g).map(b => String.fromCharCode(parseInt(b, 16))).join('');
        return /^[A-Z]{4}$/.test(vendor) ? vendor + h.slice(8) : null;
    }

    // Kandidat SN dari isi QR/barcode (bisa berisi SN perangkat, MAC, dll.), urut dari yang paling mungkin GPON SN.
    function candidates(text) {
        const t = String(text).toUpperCase();
        const out = [];
        for (let i = 0; i + 16 <= t.length; i++) { const p = hexToPon(t.slice(i, i + 16)); if (p) out.push(p); }
        (t.match(/[A-Z]{4}[0-9A-F]{8}/g) || []).forEach(x => out.push(x));
        const labeled = (t.match(/S\/?N\s*[:=]?\s*([A-Z0-9]{8,24})/) || [])[1];
        if (labeled) out.push(hexToPon(labeled) || labeled);
        (t.match(/[A-Z0-9]{8,24}/g) || []).sort((a, b) => b.length - a.length).forEach(x => out.push(hexToPon(x) || x));
        return [...new Set(out)].slice(0, 5);
    }

    async function stop(box) {
        try { if (scanner?.isScanning) await scanner.stop(); scanner?.clear(); } catch (e) {}
        if (box) box.style.display = 'none';
    }

    // box: elemen pembungkus (ditampilkan saat kamera aktif); readerId: div tempat video; onDecoded(teks, kandidat).
    async function start(box, readerId, onDecoded) {
        if (!window.Html5Qrcode) { OntApp.toast('Library scanner gagal dimuat. Periksa koneksi internet.', 'danger'); return; }
        box.style.display = '';
        scanner = new Html5Qrcode(readerId);
        try {
            await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 260, height: 160 } }, decoded => {
                stop(box);
                onDecoded(String(decoded), candidates(decoded));
            });
        } catch (e) {
            box.style.display = 'none';
            OntApp.toast('Kamera tidak bisa dibuka: ' + (e?.message || e) + '. Pastikan situs memakai HTTPS & izin kamera diberikan.', 'danger');
        }
    }

    return { hexToPon, candidates, start, stop };
})();
