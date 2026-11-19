<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
    .nm-map { height: calc(100vh - 230px); min-height: 420px; border-radius: 10px; z-index: 0; }
    .nm-node { width: 100%; height: 100%; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #fff; box-shadow: 0 0 0 3px #fff, 0 2px 6px rgba(0,0,0,.35); }
    .nm-server { background: #1d4ed8; font-size: 17px; }
    .nm-odp { background: var(--c); font-size: 13px; }
    .nm-dot { width: 14px; height: 14px; border-radius: 50%; background: var(--c); border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.4); }
    .nm-blink { animation: nmBlink 1.2s ease-in-out infinite; }
    @keyframes nmBlink { 50% { box-shadow: 0 0 0 7px rgba(220,38,38,.35); } }
    /* Garis dari pelanggan ke ODP ke server: hijau berjalan = terhubung, merah putus-putus = putus. */
    .nm-line.nm-online, .nm-line.nm-up { stroke-dasharray: 10 8; animation: nmFlow 1s linear infinite; }
    .nm-line.nm-offline, .nm-line.nm-down { stroke-dasharray: 3 7; }
    .nm-line.nm-isolated, .nm-line.nm-empty { stroke-dasharray: 2 6; opacity: .7; }
    @keyframes nmFlow { to { stroke-dashoffset: -18; } }
    @media (prefers-reduced-motion: reduce) { .nm-line, .nm-blink { animation: none !important; } }
    .nm-legend span { display: inline-flex; align-items: center; gap: 6px; margin-right: 14px; font-size: .85rem; }
    .nm-legend i.l { width: 26px; height: 0; border-top: 3px dashed; display: inline-block; }
    .nm-problems { max-height: calc(100vh - 330px); overflow: auto; }
</style>
