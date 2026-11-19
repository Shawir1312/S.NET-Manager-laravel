{{-- Koordinat peta + tombol GPS. Variabel: $lat, $lng (nilai awal). --}}
<div class="col-12">
    <label class="form-label">Lokasi di peta (opsional)</label>
    <div class="input-group">
        <input class="form-control font-monospace" type="number" step="any" min="-90" max="90" name="latitude" id="geoLat" value="{{ old('latitude', $lat) }}" placeholder="Latitude, mis. 0.7893" aria-label="Latitude">
        <input class="form-control font-monospace" type="number" step="any" min="-180" max="180" name="longitude" id="geoLng" value="{{ old('longitude', $lng) }}" placeholder="Longitude, mis. 127.3772" aria-label="Longitude">
        <button class="btn btn-outline-primary" type="button" id="geoBtn"><i class="bi bi-crosshair me-1"></i>Lokasi saya</button>
    </div>
    <div class="form-text" id="geoHint">Tekan "Lokasi saya" saat berada di lokasi (butuh izin lokasi browser), atau atur di menu Peta Jaringan.</div>
</div>
<script>
document.getElementById('geoBtn').addEventListener('click', () => {
    const hint = document.getElementById('geoHint');
    if (!navigator.geolocation) { hint.textContent = 'Browser ini tidak mendukung GPS.'; return; }
    hint.textContent = 'Mengambil lokasi…';
    navigator.geolocation.getCurrentPosition(p => {
        document.getElementById('geoLat').value = p.coords.latitude.toFixed(7);
        document.getElementById('geoLng').value = p.coords.longitude.toFixed(7);
        hint.textContent = 'Lokasi terisi (akurasi ±' + Math.round(p.coords.accuracy) + ' m).';
    }, e => { hint.textContent = 'Lokasi tidak bisa diambil: ' + e.message; }, { enableHighAccuracy: true, timeout: 15000 });
});
</script>
